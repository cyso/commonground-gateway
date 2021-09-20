<?php

namespace App\Service;

use App\Entity\Authentication;
use App\Entity\Gateway;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Constraints\DateTime;

class AuthenticationService
{
    private SessionInterface $session;
    private EntityManagerInterface $entityManager;
    private Client $client;
    private TokenStorageInterface $tokenStorage;
    private CommonGroundService $commonGroundService;

    public function __construct(SessionInterface $session, EntityManagerInterface $entityManager, TokenStorageInterface $tokenStorage, CommonGroundService $commonGroundService)
    {
        $this->session = $session;
        $this->entityManager = $entityManager;
        $this->client = new Client();
        $this->tokenStorage = $tokenStorage;
        $this->commonGroundService = $commonGroundService;
    }

    public function generateJwt()
    {
        $user = $this->retrieveCurrentUser();

        $array = [
          'username' => $user->getUsername(),
          'password' => $user->getPassword()
        ];

        $user = $this->commonGroundService->createResource($array, ['component' => 'uc', 'type' => 'login']);

        return $user['jwtToken'];

    }

    /**
     * Validates a JWT token with the public key stored in the component.
     *
     * @param string $jws       The signed JWT token to validate
     * @param string $publicKey
     *
     * @throws Exception Thrown when the JWT token could not be verified
     *
     * @return bool Whether the jwt is valid
     */
    public function validateJWTAndGetPayload(string $jws, string $publicKey): bool
    {
        try {
            $serializer = new CompactSerializer();
            $jwt = $serializer->unserialize($jws);
            $algorithmManager = new AlgorithmManager([new RS512()]);
            $pem = $this->writeFile($publicKey, 'pem');
            $public = JWKFactory::createFromKeyFile($pem);
            $this->removeFiles([$pem]);

            $jwsVerifier = new JWSVerifier($algorithmManager);

            if ($jwsVerifier->verifyWithKey($jwt, $public, 0)) {
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function checkJWTExpiration($token): bool
    {
        $data = $this->retrieveJWTContents($token);

        if (strtotime('now') >= $data['exp']) {
            return false;
        }

        return true;

    }

    public function retrieveJWTUser($token): bool
    {
        $data = $this->retrieveJWTContents($token);

        try {
            $user = $this->commonGroundService->getResource(['component' => 'uc', 'type' => 'users', 'id' => $data['userId']]);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function retrieveJWTContents($token): array
    {
        $json = base64_decode(explode('.', $token)[1]);
        return json_decode($json, true);
    }

    /**
     * Writes a temporary file in the component file system.
     *
     * @param string $contents The contents of the file to write
     * @param string $type     The type of file to write
     *
     * @return string The location of the written file
     */
    public function writeFile(string $contents, string $type): string
    {
        $stamp = microtime().getmypid();
        file_put_contents(dirname(__FILE__, 3).'/var/'.$type.'-'.$stamp, $contents);

        return dirname(__FILE__, 3).'/var/'.$type.'-'.$stamp;
    }

    /**
     * Removes (temporary) files from the filesystem.
     *
     * @param array $files An array of file paths of files to delete
     */
    public function removeFiles(array $files): void
    {
        foreach ($files as $filename) {
            unlink($filename);
        }
    }

    public function retrieveCurrentUser()
    {
        $user = $this->tokenStorage->getToken()->getUser();

        if (!is_string($user)) {
            return $user;
        }

        throw new AccessDeniedException('Unable to find logged in user');
    }

    public function authenticate(string $method, string $identifier, string $code): array
    {

        if (!$method || !$identifier) {
            throw new BadRequestException("Method and identifier can't be empty");
        }

        $authentication = $this->retrieveAuthentication($identifier);

        return $this->retrieveData($method, $code, $authentication);
    }

    public function retrieveData(string $method, string $code, Authentication $authentication): array
    {
        $redirectUrl = $this->session->get('redirectUrl');

        if (!$redirectUrl) {
            throw new BadRequestException('no redirect url found in session');
        }

        switch ($method) {
            case 'adfs':
                return $this->retrieveAdfsData($code, $authentication, $redirectUrl);
                break;
            case 'digid':
                break;
            default:
                throw new BadRequestException('Authentication method not supported');
        }

    }

    public function retrieveAdfsData(string $code, Authentication $authentication, string $redirectUrl): array
    {
        $body = [
            'client_id'         => $authentication->getClientId(),
            'client_secret'     => $authentication->getSecret(),
            'redirect_uri'      => $redirectUrl,
            'code'              => $code,
            'grant_type'        => 'authorization_code',
        ];

        $response = $this->client->request('POST', $authentication->getTokenUrl(), [
            'form_params'  => $body,
            'content_type' => 'application/x-www-form-urlencoded',
        ]);

        $accessToken = json_decode($response->getBody()->getContents(), true);

        $json = base64_decode(explode('.', $accessToken['access_token'])[1]);
        return json_decode($json, true);

    }

    public function handleAuthenticationUrl(string $method, string $identifier, string $redirectUrl): string
    {
        $authentication = $this->retrieveAuthentication($identifier);

        return $this->buildRedirectUrl($method, $redirectUrl, $authentication);

    }

    public function buildRedirectUrl(string $method, string $redirectUrl, Authentication $authentication): ?string
    {
        switch ($method) {
            case 'adfs':
                return $this->handleAdfsRedirectUrl($redirectUrl, $authentication);
                break;
            case 'digid':
                break;
            default:
                throw new BadRequestException('Authentication method not supported');
        }
    }

    public function handleAdfsRedirectUrl(string $redirectUrl, Authentication $authentication): string
    {
        return $authentication->getAuthenticateUrl() .
            '?response_type=code&response_mode=query&client_id=' .
            $authentication->getClientId() . '&^redirect_uri=' .
            $redirectUrl .
            '&scope=' .
            implode(' ', $authentication->getScopes());
    }

    public function retrieveAuthentication(string $identifier): Authentication
    {
        $authentications = $this->entityManager->getRepository('App\Entity\Authentication')->findBy(['name' => $identifier]);

        if (count($authentications) == 0 || !$authentications[0] instanceof Authentication) {
            throw new NotFoundHttpException('Unable to find Authentication');
        }

        return $authentications[0];
    }

    public function sendTokenMail(array $user, string $subject): bool
    {
        $response = $this->commonGroundService->getResourceList(['component' => 'uc', 'type' => 'users', 'id' => "{$user['id']}/token"], ['type' => 'SET_PASSWORD']);

        $service = $this->commonGroundService->getResourceList(['component' => 'bs', 'type' => 'services'])['hydra:member'][0];

        $message = $this->commonGroundService->createResource(
            [
                'reciever' => $user['username'],
                'sender' => 'taalhuizen@biscutrecht.nl',
                'content' => "{$response['token']}",
                'type' => 'email',
                'status' => 'queued',
                'service' => '/services/'.$service['id'],
                'subject' => $subject,
            ],
            ['component' => 'bs', 'type' => 'messages']);
        return true;
    }

}

<?php

namespace App\Service;

use App\Entity\CollectionEntity;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class PubliccodeService
{
    private EntityManagerInterface $entityManager;
    private ParameterBagInterface $params;

    public function __construct(
        EntityManagerInterface $entityManager,
        ParameterBagInterface  $params
    )
    {
        $this->entityManager = $entityManager;
        $this->params = $params;
        $this->github = $this->params->get('github_key') ? new Client(['base_uri' => 'https://api.github.com/', 'headers' => ['Authorization' => 'Bearer ' . $this->params->get('github_key')]]) : null;
    }

    /**
     * This function gets the github owner details
     *
     * @param array $item a repository from github with a publicclode.yaml file
     * @param bool $ownerRepos for setting the repos of the owner
     * @return array
     * @throws GuzzleException
     */
    public function getGithubOwnerInfo(array $item, bool $ownerRepos): array
    {
        $ownerRepos ? $repos = json_decode($this->getGithubOwnerRepositories($item['owner']["login"])) : $repos = null;
        $publiccode = $this->findPubliccode($item);
        $publiccode !== null ? $avatars = $this->getGithubOwnerLogos($publiccode, $item) : $avatars = null;

        return [
            "id" => $item['owner']["id"],
            "type" => $item['owner']["type"],
            "login" => $item['owner']["login"] ?? null,
            "html_url" => $item['owner']["html_url"] ?? null,
            "organizations_url" => $item['owner']["organizations_url"] ?? null,
            "avatars" => $avatars ?? null,
            "repos" => $repos
        ];
    }

    /**
     * This function gets all the github repository details
     *
     * @param array $item a repository from github with a publicclode.yaml file
     * @param bool|false $ownerRepos
     * @return array
     * @throws GuzzleException
     */
    public function getGithubRepositoryInfo(array $item, bool $ownerRepos): array
    {
       return [
            "id" => $item["id"],
            "name" => $item["name"],
            "full_name" => $item["full_name"],
            "description" => $item["description"],
            "html_url" => $item["html_url"],
            "private" => $item["private"],
            'owner' => $item['owner']["type"] === 'Organization' ? $this->getGithubOwnerInfo($item, $ownerRepos) : null
        ];
    }

    /**
     * This function gets all the github owner details
     *
     * @param array $publiccode the publiccode from a repository
     * @param array $repository a github repository
     * @return array
     */
    public function getGithubOwnerLogos(array $publiccode, array $repository): array
    {
        $avatars = [];

        if (!empty($publiccode['logo']) && filter_var($publiccode['logo'], FILTER_VALIDATE_URL) === false) {
            $avatars['logo'] = $this->handleUrl($publiccode['logo'], $repository["name"], $repository['owner']["login"]);
        }

        if (!empty($publiccode['monochromeLogo']) && filter_var($publiccode['monochromeLogo'], FILTER_VALIDATE_URL) === false) {
            $avatars['monochromeLogo'] = $this->handleUrl($publiccode['monochromeLogo'], $repository["name"], $repository['owner']["login"]);
        }

        return $avatars;
    }

    /**
     * This function gets all the repositories of the owner
     *
     * @param string $owner the name of the owner of a repository
     * @return string|false
     * @throws GuzzleException
     */
    public function getGithubOwnerRepositories(string $owner): ?string
    {
        if ($response = $this->github->request('GET', '/orgs/' . $owner . "/repos")) {
            return $response->getBody()->getContents();
        }
        return null;
    }

    /**
     * This function gets the content of a github file
     *
     * @param array $repository a github repository
     * @param string $file the file that we want to search
     * @return string|null
     * @throws GuzzleException
     */
    public function getGithubFileContent(array $repository, string $file): ?string
    {
        $path = $this->getRepoPath($repository['html_url']);
        $client = new Client(['base_uri' => 'https://raw.githubusercontent.com/' . $path . '/main/', 'http_errors' => false]);
        $response = $client->get($file);

        if ($response->getStatusCode() == 200) {
            $result = strval($response->getBody());
        } else {
            return null;
        }

        // Lets grab symbolic links
        if (!substr_compare($result, $file, -strlen($file), strlen($file))) {
            return $this->getGithubFileContent($repository, $result);
        }

        return $result;
    }


    /**
     * This function finds a publiccode yaml file in a repository
     *
     * @param array $repository a github repository
     * @return array|null
     * @throws GuzzleException
     */
    public function findPubliccode(array $repository): ?array
    {
        $publiccode = $this->getGithubFileContent($repository, 'publiccode.yml');
        if (!$publiccode) {
            $publiccode = $this->getGithubFileContent($repository, 'publiccode.yaml');
        }

        // Lets parse the public code yaml/yml
        try {
            $publiccode ? $publiccode = Yaml::parse($publiccode) : $publiccode = null;
        } catch (ParseException $exception) {
            return null;
        }

        return $publiccode;
    }

    /**
     * This function gets the path of a github repository
     *
     * @param string $html_url a github repository html_url
     * @return string
     */
    public function getRepoPath(string $html_url): string
    {
        $parse = parse_url($html_url);
        $path = $parse['path'];
        $path = str_replace(['.git'], '', $path);

        return rtrim($path, '/');
    }

    /**
     * This function handles the given url
     *
     * @param string $url the url from the publiccode yaml file
     * @param string $repoName the name of the repository
     * @param string $owner the name of the owner of a repository
     * @return string
     */
    public function handleUrl(string $url, string $repoName, string $owner): string
    {
        if (strpos($url, "://") === false && substr($url, 0, 1) != "/") $url = "http://" . $url;
        $parsedUrl = parse_url($url);

        if (empty($parsedUrl['host'])) {
            $url = "github.com/$owner/$repoName/raw/master/" . $url;
        } elseif (!empty($parsedUrl['host']) && strpos($parsedUrl['host'], 'github') == false) {
            $url = "github.com/$owner/$repoName/raw/master/" . $url;
        }

        $parsedUrl = parse_url($url);
        if (empty($parsedUrl['scheme'])) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    /**
     * This function check if the github key is provided
     *
     * @return Response|null
     */
    public function checkGithubKey(): ?Response
    {
        if (!$this->github) {
            return new Response(
                'Missing github_key in env',
                Response::HTTP_BAD_REQUEST,
                ['content-type' => 'json']
            );
        }
        return null;
    }

    /**
     * This function gets the content of a specific repository
     *
     * @param string $id
     * @return array|null
     * @throws GuzzleException
     */
    public function getGithubRepositoryContent(string $id): ?array
    {
        $this->checkGithubKey();
        $response = $this->github->request('GET', 'https://api.github.com/repositories/' . $id);
        return $this->getGithubRepositoryInfo(json_decode($response->getBody()->getContents(), true), true);
    }

    /**
     * This function creates a Collection from a github repository
     *
     * @param string $id id of the github repository
     * @return CollectionEntity
     * @throws GuzzleException
     */
    public function createCollection(string $id): CollectionEntity
    {
        $this->checkGithubKey();
        $response = $this->github->request('GET', 'https://api.github.com/repositories/' . $id);
        $repository = json_decode($response->getBody()->getContents(), true);
        $publiccode = $this->findPubliccode($repository);

        $collection = new CollectionEntity();
        $collection->setName($repository['name']);
        $collection->setDescription($repository['description']);
        $collection->setSourceType('url');
        $collection->setSourceUrl($repository['html_url']);
        $collection->setSourceBranch($repository['default_branch']);
        $collection->setLocationOAS($publiccode ? $publiccode['description']['en']['apiDocumentation'] : null);
        isset($publiccode['description']['en']['testDataLocation']) && $collection->setTestDataLocation($publiccode['description']['en']['testDataLocation']);

        $this->entityManager->persist($collection);
        $this->entityManager->flush();

        return $collection;
    }

    /**
     * This function is searching for repositories containing a publiccode.yaml file.
     *
     * @return ?string|Response
     * @throws GuzzleException
     */
    public function discoverGithub(): string
    {
        $this->checkGithubKey();

        $query = [
            'page' => 1,
            'per_page' => 100,
            'order' => 'desc',
            'sort' => 'author-date',
            'q' => 'publiccode in:path path:/  extension:yaml', // so we are looking for a yaml file called public code based in the repo root
        ];

        try {
            $response = $this->github->request('GET', '/search/code', ['query' => $query]);
        } catch (ClientException $exception) {
            return new Response(
                $exception,
                Response::HTTP_BAD_REQUEST,
                ['content-type' => 'json']
            );
        }

        $response = json_decode($response->getBody()->getContents(), true);
        $pages = ceil($response['total_count'] / 100);

        $results["repositories"] = [];
        while ($query['page'] <= $pages) {
            foreach ($response['items'] as $item) {
                array_push($results["repositories"], $this->getGithubRepositoryInfo($item['repository'], false));
            }

            // Up the page number
            $query['page']++;

            // Let avoid over asking the git api
            sleep(1);
        }

        return json_encode($results);
    }
}

<?php

namespace App\Service\Validation\Rules;

use Exception;
use Respect\Validation\Rules\AbstractRule;
use Respect\Validation\Validatable;
use Respect\Validation\Validator;

final class Base64String extends AbstractRule
{
    /**
     * Used for Base64StringException
     *
     * @var string|null
     */
    private ?string $exceptionMessage;

    /**
     * @inheritDoc
     */
    public function validate($input): bool
    {
        // Example of how input should look like: data:text/plain;base64,ZGl0IGlzIGVlbiB0ZXN0IGRvY3VtZW50
        // Make sure we have a string as input
        if (!Validator::stringType()->validate($input)) {
            $this->setExceptionMessage("This is not a string.");
            return false;
        }

        // Get base64 from this input string and validate it
        $exploded_input = explode(',', $input);
        $base64 = end($exploded_input);
        if (!Validator::base64()->validate($base64)) {
            $this->setExceptionMessage("This is not a valid base64.");
            return false;
        }

        try {
            $mimeType1 = false;
            // Get mimeType from the base64 input string and compare it with the mimeType we get from using the base64 to open a file and get the mimeType
            // If input string has a single comma (',') in it count($exploded_input) will return 2
            if (count($exploded_input) == 2) {
                // Take the part before the comma (',') and look for the mimeType in it
                $matchesCount = preg_match('/data:([\w\/]+);base64/', $exploded_input[0], $matches);
                if ($matchesCount == 1) {
                    // We found a mimeType in the base64 input string
                    $mimeType1 = $matches[1];
                }
            }

            // Use the base64 to open a file and get the mimeType
            $fileData = base64_decode($base64);
            $f = finfo_open();
            $mimeType2 = finfo_buffer($f, $fileData, FILEINFO_MIME_TYPE);
            finfo_close($f);

            if ($mimeType1 !== $mimeType2) {
                $this->setExceptionMessage("Mime type mismatch: ".$mimeType1." should match: ".$mimeType2);
                return false;
            }
        } catch (Exception $exception) {
            $this->setExceptionMessage($exception->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @return string|null
     */
    public function getExceptionMessage(): ?string
    {
        return $this->exceptionMessage;
    }

    /**
     * @param string $exceptionMessage
     * @return Validatable
     */
    public function setExceptionMessage(string $exceptionMessage): Validatable
    {
        $this->exceptionMessage = $exceptionMessage;

        return $this;
    }
}

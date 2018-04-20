<?php
/**
 * Created by PhpStorm.
 * User: franzwilding
 * Date: 20.04.18
 * Time: 09:27
 */

namespace App\Bundle\CoreBundle\Exception;

use GraphQL\Error\ClientAware;

/**
 * This exception should be thrown when an invalid field configuration was found during operation. When validating
 * field settings on domain crate / update, don't throw this Exception but an InvalidArgumentException.
 *
 * Class InvalidFieldConfigurationException
 * @package App\Bundle\CoreBundle\Exception
 */
class InvalidFieldConfigurationException extends \Exception implements ClientAware
{

    /**
     * Returns true when exception message is safe to be displayed to a client.
     *
     * @api
     * @return bool
     */
    public function isClientSafe()
    {
        return true;
    }

    /**
     * Returns string describing a category of the error.
     *
     * Value "graphql" is reserved for errors produced by query parsing or validation, do not use it.
     *
     * @api
     * @return string
     */
    public function getCategory()
    {
        return 'field';
    }
}
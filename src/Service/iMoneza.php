<?php
/**
 * iMoneza Service
 *
 * This is a stateful service for interacting with the API
 *
 * @author Aaron Saray
 */

namespace iMoneza\Drupal\Service;
use iMoneza\Connection;
use iMoneza\Data\None;
use iMoneza\Data\ResourceAccess;
use iMoneza\Exception;
use iMoneza\Helper;
use iMoneza\Options\Access\GetResourceFromResourceKey;
use iMoneza\Options\Access\GetResourceFromTemporaryUserToken;
use iMoneza\Options\Management\GetProperty;
use iMoneza\Options\Management\GetResource;
use iMoneza\Options\Management\SaveResource;
use iMoneza\Options\OptionsAbstract;
use iMoneza\Request\Curl;
use iMoneza\Drupal\Filter\ExternalResourceKey;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Class iMoneza
 * @package iMoneza\WordPress\Service
 */
class iMoneza
{
    /**
     * @var int used to indicate the manage API
     */
    const API_TYPE_MANAGE = 1;

    /**
     * @var int used to indicate the access API
     */
    const API_TYPE_ACCESS = 2;

    /**
     * @var string
     */
    protected $manageApiUrl;

    /**
     * @var string
     */
    protected $accessApiUrl;
    
    /**
     * @var string
     */
    protected $managementApiKey;

    /**
     * @var string
     */
    protected $managementApiSecret;

    /**
     * @var string
     */
    protected $accessApiKey;

    /**
     * @var string
     */
    protected $accessApiSecret;

    /**
     * @var string the last error
     */
    protected $lastError = '';

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var ExternalResourceKey
     */
    protected $externalResourceKeyFilter;

    /**
     * Create this service
     * 
     * @param ExternalResourceKey $externalResourceKeyFilter
     */
    public function __construct(ExternalResourceKey $externalResourceKeyFilter)
    {
        $this->externalResourceKeyFilter = $externalResourceKeyFilter;
    }

    /**
     * @param string $manageApiUrl
     * @return iMoneza
     */
    public function setManageApiUrl($manageApiUrl)
    {
        $this->manageApiUrl = $manageApiUrl;
        return $this;
    }

    /**
     * @param string $accessApiUrl
     * @return iMoneza
     */
    public function setAccessApiUrl($accessApiUrl)
    {
        $this->accessApiUrl = $accessApiUrl;
        return $this;
    }
    
    /**
     * @param string $managementApiKey
     * @return iMoneza
     */
    public function setManagementApiKey($managementApiKey)
    {
        $this->managementApiKey = $managementApiKey;
        return $this;
    }

    /**
     * @param string $managementApiSecret
     * @return iMoneza
     */
    public function setManagementApiSecret($managementApiSecret)
    {
        $this->managementApiSecret = $managementApiSecret;
        return $this;
    }

    /**
     * @param string $accessApiKey
     * @return iMoneza
     */
    public function setAccessApiKey($accessApiKey)
    {
        $this->accessApiKey = $accessApiKey;
        return $this;
    }

    /**
     * @param string $accessApiSecret
     * @return iMoneza
     */
    public function setAccessApiSecret($accessApiSecret)
    {
        $this->accessApiSecret = $accessApiSecret;
        return $this;
    }

    /**
     * @return string
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Gets a URL to redirect for access OR false if its granted
     *
     * @param \stdClass $node
     * @param null $iMonezaTUT
     * @return bool|string
     * @throws Exception\DecodingError
     * @throws Exception\TransferError
     */
    public function getResourceAccessRedirectURL(\stdClass $node, $iMonezaTUT = null)
    {
        $result = false;
        $keyFilter = $this->externalResourceKeyFilter;
        
        if ($iMonezaTUT) {
            $options = new GetResourceFromTemporaryUserToken();
            $options->setTemporaryUserToken($iMonezaTUT);
        }
        else {
            $options = new GetResourceFromResourceKey();
            $options->setUserToken($this->getUserTokenFromCookie());
        }
        
        $options->setResourceKey($keyFilter($node))
            ->setIP(Helper::getCurrentIP());
        $this->prepareForRequest($options, self::API_TYPE_ACCESS);

        /** @var \iMoneza\Data\ResourceAccess $data */
        $data = $this->getConnectionInstance()->request($options, $options->getDataObject());
        $this->setUserTokenCookie($data->getUserToken(), $data->getUserTokenExpiration());

        if ($data->getAccessAction() != ResourceAccess::ACCESS_ACTION_GRANT) {
            $result = $data->getAccessActionUrl();
        }

        return $result;
    }

    /**
     * Get a resource from a node
     * 
     * @param \stdClass $node
     * @return \iMoneza\Data\Resource|null
     */
    public function getResource(\stdClass $node)
    {
        $keyFilter = $this->externalResourceKeyFilter;

        $options = new GetResource();
        $options->setResourceKey($keyFilter($node));
        $this->prepareForRequest($options);
        
        $data = null;
        
        try {
            /** @var \iMoneza\Data\Resource $data */
            $data = $this->getConnectionInstance()->request($options, $options->getDataObject());
        }
        catch (Exception\iMoneza $e) {
            $this->lastError = sprintf(t('Something went wrong with the system: %s'), $e->getMessage());
        }
        return $data;
    }

    /**
     * @param \stdClass $node
     * @param $pricingGroupId
     * @return bool
     */
    public function createOrUpdateResource(\stdClass $node, $pricingGroupId)
    {
        $filter = $this->externalResourceKeyFilter;
        $options = new SaveResource();
        $options->setPricingGroupId($pricingGroupId)
            ->setExternalKey($filter($node))
            ->setName($node->title)
            ->setTitle($node->title)
            ->setPublicationDate(new \DateTime('@' . $node->created));

        // not sure why but sometimes this is not set? @todo figure out why
        if (!empty($node->body[$node->language][0]['summary'])) $options->setDescription($node->body[$node->language][0]['summary']);

        $this->prepareForRequest($options);

        $result = false;
        try {
            $this->getConnectionInstance()->request($options, new None());
            $result = true;
        }
        catch (Exception\iMoneza $e) {
            $this->lastError = sprintf(t('Something went wrong with the system: %s'), $e->getMessage());
        }

        return $result;
    }

    /**
     * @return \iMoneza\Data\Property|false
     */
    public function getProperty()
    {
        $options = new GetProperty();
        $this->prepareForRequest($options);

        $result = false;
        try {
            /** @var \iMoneza\Data\Property $result */
            $result = $this->getConnectionInstance()->request($options, new \iMoneza\Data\Property());
        }
        catch (Exception\NotFound $e) {
            $this->lastError = t("Oh no!  Looks like your Management API Key isn't working. Look closely - does it look right?");
        }
        catch (Exception\AuthenticationFailure $e) {
            $this->lastError = t("Looks like the API key and secret don't match properly.  Go back and make sure you're using the exact API Management KEY and SECRET.  Thanks!");
        }
        catch (Exception\iMoneza $e) {
            $this->lastError = sprintf(t('Something went wrong with the system: %s'), $e->getMessage());
        }

        return $result;
    }

    /**
     * Stub currently
     * @return bool
     */
    public function validateResourceAccessApiCredentials()
    {
        $options = new GetResourceFromResourceKey();
        $options->setResourceURL('api-validation')->setResourceKey('api-validation')->setIP(Helper::getCurrentIP());
        $this->prepareForRequest($options, self::API_TYPE_ACCESS);

        $result = false;
        try {
            $this->getConnectionInstance()->request($options, $options->getDataObject());
            $result = true;
        }
        catch (Exception\NotFound $e) {
            $this->lastError = t("It seems like your resource access API key is wrong. Check and see if there are any obvious problems - otherwise, delete it and try again please.");
        }
        catch (Exception\AuthenticationFailure $e) {
            $this->lastError = t("Your resource access API secret looks wrong.  Can you give it another shot?");
        }
        catch (Exception\iMoneza $e) {
            $this->lastError = sprintf(t('Something went wrong with the system: %s'), $e->getMessage());
        }

        return $result;
    }

    /**
     * @return Connection
     */
    protected function getConnectionInstance()
    {
        if (is_null($this->connection)) {
            $logger = new Logger(__CLASS__);
            if (IMONEZA_DEBUG) {
                $logger->pushHandler(new StreamHandler('php://stderr'));
            }
            $this->connection = new Connection($this->managementApiKey, $this->managementApiSecret, $this->accessApiKey, $this->accessApiSecret, new Curl(), $logger);
        }

        return $this->connection;
    }

    /**
     * @param OptionsAbstract $options
     * @param int $type
     */
    protected function prepareForRequest(OptionsAbstract $options, $type = self::API_TYPE_MANAGE)
    {
        $this->lastError = '';
        $options->setApiBaseURL($type == self::API_TYPE_MANAGE ? $this->manageApiUrl : $this->accessApiUrl);
    }

    /**
     * Sets the user token cookie way in the future and HTTP only
     * @param $userToken
     * @param \DateTime $userTokenExpiration
     */
    protected function setUserTokenCookie($userToken, \DateTime $userTokenExpiration = null)
    {
        $expiration = $userTokenExpiration ? $userTokenExpiration->getTimestamp() : null;
        setcookie('imoneza-user-token', $userToken, $expiration, '/', null, null, true); 
    }

    /**
     * Get the current user token
     *
     * @return string|null
     */
    protected function getUserTokenFromCookie()
    {
        return isset($_COOKIE['imoneza-user-token']) ? $_COOKIE['imoneza-user-token'] : null;
    }
}
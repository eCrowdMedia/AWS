<?php

require_once('../vendor/autoload.php');
use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\CognitoIdentityProvider\Exception\CognitoIdentityProviderException;

use Aws\CognitoIdentity\CognitoIdentityClient;
use Aws\CognitoIdentity\Exception\CognitoIdentityException;


class cognito_test
{
    protected $client_id            = '<client id>';
    protected $client_secret        = '<client secret>';
    protected $user_pool_id         = '<user pool id>';
    protected $identity_pool_id     = '<identity pool id>';

    protected $iam_access_key       = '<access key>';
    protected $iam_access_secret    = '<access secret>';

    protected $region               = '<region>';
    protected $version              = 'latest';

    protected $cognigoUserPool      = '';
    protected $appid                = '';
    protected $identity_provider_client;
    protected $identity_client;
    protected $default_config       = [];

    /**
     * 預設值補充，動作操作判斷
     */
    public function __construct()
    {
        $this->default_config = [
            'version' => $this->version,
            'region' => $this->region,
            'credentials' => [
                'key'    => $this->iam_access_key,
                'secret' => $this->iam_access_secret,
            ],
        ];
    }

    /**
     *
     * aws 官方 Class CognitoIdentityProviderClient
     * 官方提供 api：https://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.CognitoIdentityProvider.CognitoIdentityProviderClient.html
     */
    public function main_identity_provider()
    {
        try{

            $this->identity_provider_client = new CognitoIdentityProviderClient($this->default_config);

            // 取得身份驗證結果
            $username            = "testOperator213";
            $password            = "11111111";
            $authenticate_result = $this->authenticate($username, $password);

            // 取單一 user
            // $result = $this->get_user($authenticate_result['AccessToken']);

            // 取 user 列表
            // $result = $this->list_users();

            echo '<pre>';print_r($authenticate_result);exit;

        } catch (CognitoIdentityProviderException $exception) {
            echo '<pre>';print_r($exception->getMessage());exit;
        }
    }

    /**
     *
     * aws 官方 Class CognitoIdentityClient
     * 官方提供 api：https://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.CognitoIdentity.CognitoIdentityClient.html
     */
    public function main_identity()
    {
        try{
            $this->identity_client = new CognitoIdentityClient($this->default_config);

            $result = $this->identity_client->getId([
                'IdentityPoolId' => $this->identity_pool_id,
            ]);


            echo '<pre>';print_r($result);exit;

        } catch (CognitoIdentityException $exception) {
            echo '<pre>';print_r($exception->getMessage());exit;
        }
    }

    /**
     * 取得 user 身份驗證，取得驗證結果
     * AccessToken、IdToken、RefreshToken、NewDeviceMetadata、TokenType、ExpiresIn
     */
    public function authenticate($username, $password)
    {
        try {
            $result = $this->identity_provider_client->adminInitiateAuth([
                'AuthFlow' => 'ADMIN_NO_SRP_AUTH', // REQUIRED
                'AuthParameters' => [
                    "USERNAME" => $username,
                    "PASSWORD" => $password,
                    "SECRET_HASH" => base64_encode(hash_hmac(
                        'sha256',
                        $username. $this->client_id,
                        $this->client_secret,
                        true
                    ))
                ],
                'ClientId' => $this->client_id, // REQUIRED
                'UserPoolId' => $this->user_pool_id, // REQUIRED
            ]);
        } catch (CognitoIdentityProviderException $exception) {
            throw $exception;
        }

        return $result;
    }

    /**
     * 取單一個 user
     * 使用參數：https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-cognito-idp-2016-04-18.html#getuser
     */
    public function get_user($access_token)
    {
        return $this->identity_provider_client->getUser([
            'AccessToken' => $access_token,
        ])['UserAttributes'];
    }

    /**
     * 取 user 列表
     * 使用參數：https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-cognito-idp-2016-04-18.html#listusers
     */
    public function list_users()
    {
        return $this->identity_provider_client->listUsers([
            'AttributesToGet' => ['email'],
            'Limit' => 10,
            'UserPoolId' => $this->user_pool_id, // REQUIRED
        ])['Users'];
    }

}

$cognito_test = new cognito_test();
$cognito_test->main_identity_provider();
// $cognito_test->main_identity();
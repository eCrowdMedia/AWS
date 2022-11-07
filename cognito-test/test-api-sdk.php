<?php
require_once('../vendor/autoload.php');
use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\CognitoIdentityProvider\Exception\CognitoIdentityProviderException;
use Aws\CognitoIdentityProvider\ValueObject\AttributeType;

use Aws\CognitoIdentity\CognitoIdentityClient;
use Aws\CognitoIdentity\Exception\CognitoIdentityException;

use Facebook\Facebook;

class cognito_test
{
    protected $client_id            = '';
    protected $client_secret        = '';
    protected $user_pool_id         = '';
    protected $identity_pool_id     = '';
    protected $iam_access_key       = '';
    protected $iam_access_secret    = '';
    protected $region               = '';
    protected $version              = '';
    protected $redirect_url         = '';

    protected $identity_provider_client;
    protected $identity_client;
    protected $default_config       = [];
    protected $env_data             = [];

    /**
     * 預設值補充，動作操作判斷
     */
    public function __construct()
    {
        session_start();
        $this->env_data = include_once("test_api_env.php");

        $this->client_id            = $this->env_data['general']['client_id'];
        $this->client_secret        = $this->env_data['general']['client_secret'];
        $this->user_pool_id         = $this->env_data['general']['user_pool_id'];
        $this->identity_id          = $this->env_data['sdk']['identity_id'];
        $this->identity_pool_id     = $this->env_data['sdk']['identity_pool_id'];
        $this->iam_access_key       = $this->env_data['general']['iam_access_key'];
        $this->iam_access_secret    = $this->env_data['general']['iam_access_secret'];
        $this->region               = $this->env_data['general']['region'];
        $this->version              = $this->env_data['general']['version'];
        $this->redirect_url         = $this->env_data['sdk']['redirect_url'];

        $this->default_config = [
            'version' => $this->version,
            'region' => $this->region,
            'credentials' => [
                'key'    => $this->iam_access_key,
                'secret' => $this->iam_access_secret,
            ],
        ];

        $this->identity_provider_client = new CognitoIdentityProviderClient($this->default_config);
        $this->identity_client = new CognitoIdentityClient($this->default_config);

        if (isset($_GET['signout']) or $_GET['signout'] == 1) {
            $this->global_signout();
            $this->_user_logout();
        }

        if (isset($_GET['revoke_token']) or $_GET['revoke_token'] == 1) {
            $this->revoke_token();
            $this->_user_logout();
        }

        if (isset($_GET['create_user']) or $_GET['create_user'] == 1) {
            $this->create_user();
        }

        if (isset($_GET['delete_user']) or $_GET['delete_user'] == 1) {
            $this->delete_user();
        }

        s($_POST);
        if (!empty($_POST['username']) and !empty($_POST['password'])) {
            $this->main_identity_provider($_POST['username'], $_POST['password']);
        }
    }


    public function google_login()
    {
        // create Client Request to access Google API
        $client = new Google_Client();
        $client->setAuthConfig('client_secret_385299543889.json');
        $client->addScope("email");
        $client->addScope("profile");

        // authenticate code from Google OAuth Flow
        if (isset($_GET['code'])) {
            $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
            $client->setAccessToken($token['access_token']);
            sd($token);
            // get profile info
            // $google_oauth = new Google_Service_Oauth2($client);
            // $google_account_info = $google_oauth->userinfo->get();
            // $email =  $google_account_info->email;
            // $name =  $google_account_info->name;

            // now you can use this profile info to create account in your website and make user logged in.
        } else {
            echo "<a href='".$client->createAuthUrl()."'>Google Login</a>";
        }
    }

    public function fb_login()
    {
        try {

            $app_id = $this->env_data['fb_paramters']['app_id'];
            $app_secret = $this->env_data['fb_paramters']['app_secret'];

            $fb = new Facebook([
                'app_id' => $app_id,
                'app_secret' => $app_secret,
                'default_graph_version' => 'v15.0',
            ]);

            $helper = $fb->getRedirectLoginHelper();

            $permissions = ['email'];
            $loginUrl = $helper->getLoginUrl($this->redirect_url, $permissions);

            echo '<a href="' . htmlspecialchars($loginUrl) . '">Log in with Facebook!</a>';
            $helper = $fb->getRedirectLoginHelper();
            $accessToken = $helper->getAccessToken();

            $fb_access_token = $this->check_fb_token($accessToken, $fb, $app_id);



            return $fb_access_token;

            // $response = $fb->get('/me', $fb_access_token);

            // sd($response->getGraphUser()->getId());
        } catch(\Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage();
            exit;
        } catch(\Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage();
            exit;
        }
    }

    private function check_fb_token(object $accessToken, object $fb, string $app_id)
    {
        $helper = $fb->getRedirectLoginHelper();
        if (! isset($accessToken)) {
            if ($helper->getError()) {
                header('HTTP/1.0 401 Unauthorized');
                echo "Error: " . $helper->getError() . "\n";
                echo "Error Code: " . $helper->getErrorCode() . "\n";
                echo "Error Reason: " . $helper->getErrorReason() . "\n";
                echo "Error Description: " . $helper->getErrorDescription() . "\n";
            } else {
                header('HTTP/1.0 400 Bad Request');
                echo 'Bad request';
            }
            exit;
        }

        // The OAuth 2.0 client handler helps us manage access tokens
        $oAuth2Client = $fb->getOAuth2Client();

        // Get the access token metadata from /debug_token
        $tokenMetadata = $oAuth2Client->debugToken($accessToken);

        $tokenMetadata->validateAppId($app_id);
        $tokenMetadata->validateExpiration();

        if (! $accessToken->isLongLived()) {
            // Exchanges a short-lived access token for a long-lived one
            try {
              $accessToken = $oAuth2Client->getLongLivedAccessToken($accessToken);
            } catch (\Facebook\Exceptions\FacebookSDKException $e) {
              echo "<p>Error getting long-lived access token: " . $helper->getMessage() . "</p>\n\n";
              exit;
            }

            echo '<h3>Long-lived</h3>';
            var_dump($accessToken->getValue());
        }

        return (string)$accessToken->getValue();
    }

    /**
     *
     * aws 官方 Class CognitoIdentityProviderClient
     * 官方提供 api：https://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.CognitoIdentityProvider.CognitoIdentityProviderClient.html
     */
    public function main_identity_provider(string $username, string $password)
    {
        try{
            $authenticate_result = $this->authenticate($username, $password);

            $this->_set_session([
                'access_token' => $authenticate_result['AuthenticationResult']['AccessToken']
            ]);

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
            echo '<pre>';print_r($exception->getMessage());exit;
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
    public function list_users(int $limit = 10)
    {
        return $this->identity_provider_client->listUsers([
            'AttributesToGet' => ['email'],
            'Limit' => $limit,
            'UserPoolId' => $this->user_pool_id, // REQUIRED
        ])['Users'];
    }

    /**
     * 登出
     */
    public function global_signout(string $access_token = '')
    {
        try {
            $accessToken = !empty($access_token) ? $access_token : $_SESSION['access_token'];

            if (empty($accessToken)) {
                return true;
            }

            $this->identity_provider_client->globalSignOut([
                'AccessToken' => $accessToken, // REQUIRED
            ]);

        } catch (CognitoIdentityProviderException $exception) {
            $this->_user_logout();
            echo '<pre>';print_r($exception->getMessage());exit;
        }
    }

    /**
     * 使用者登出
     */
    private function _user_logout()
    {
        $this->_unset_session([
            'access_token',
        ]);

        header('Location: '.$this->redirect_url);
    }

    public function revoke_token()
    {
        try{
            $this->identity_provider_client->revokeToken([
                'Token' => 'refresh_token',
                'ClientId' => $this->env_data['general']['client_id'],
                'ClientSecret' => $this->env_data['general']['client_secret'],
            ]);
        } catch (CognitoIdentityProviderException $exception) {
            echo '<pre>';print_r($exception->getMessage());exit;
        }
    }

    public function create_user()
    {
        try {
            $this->identity_provider_client->adminCreateUser([
                'UserPoolId' => $this->user_pool_id,
                'Username' => 'QWE123',
                'TemporaryPassword' => 'QWEqwe123!@#',
                'UserAttributes' => [
                    [
                        "Name" => "email",
                        "Value" => "QWE123-test@gmail.com"
                    ]
                ]
            ]);
        } catch (CognitoIdentityProviderException $exception) {
            echo '<pre>';print_r($exception->getMessage());exit;
        }

        return true;
    }

    public function delete_user()
    {
        try{
            $this->identity_provider_client->adminDeleteUser([
                'UserPoolId' => $this->user_pool_id,
                'Username' => 'QWE123',
            ]);
        } catch (CognitoIdentityProviderException $exception) {
            echo '<pre>';print_r($exception->getMessage());exit;
        }

        header('Location: '.$this->redirect_url);
    }

    /**
     * user 點擊使用 fb 登入，拿 fb token 再向 cognito 取token
     */
    public function get_id_by_fb()
    {
        $fb = $this->fb_login();
        // sd($fb);

        // $qq = $this->identity_client->getId([
        //     'AccountId' => '430124335147',
        //     'IdentityPoolId' => 'ap-northeast-1:71f145a2-28a4-4094-be32-20aefb6a9c1a',
        //     'Logins' => [
        //         'graph.facebook.com' => $fb
        //     ]
        // ]);
        $qq = $this->identity_client->getOpenIdToken([
            'IdentityId' => $this->identity_id,
            'Logins' => [
                'graph.facebook.com' => $fb
            ]
        ]);

        sd($qq);
    }

    public function operator()
    {
        if (isset($_SESSION['access_token'])) {
            echo ' == ';
            echo '<a href="?signout=1">sign out</a>';
            echo ' == ';

            // 取單一 user
            $result = $this->get_user($_SESSION['access_token']);

            // 取 user 列表
            $list_users_result = $this->list_users();

            sd($_SESSION, $result, $list_users_result);
        }

        echo ' == ';
        echo '<a href="?revoke_token=1">Revoke token</a>';
        echo ' == ';
        echo '<a href="?create_user=1">create user</a>';
        echo ' == ';
        echo '<a href="?delete_user=1">delete user</a>';

        echo '<form action="" method="POST">';
        echo '帳號：<input type="text" value="" name="username">';
        echo '<BR>';
        echo '密碼：<input type="text" value="" name="password">';
        echo '<input type="submit" value="submit" name="submit">';
        echo '</form>';

        $this->get_id_by_fb();
    }

    /**
     * 加入sesssion
     */
    private function _set_session($session_array)
    {
        foreach ($session_array as $key => $val) {
            $_SESSION[$key] = $val;
        }
    }

    /**
     * 移除 session
     */
    private function _unset_session($session_array)
    {
        foreach ($session_array as $key) {
            unset($_SESSION[$key]);
        }
    }
}

$cognito_test = new cognito_test();
$cognito_test->operator();
// $cognito_test->get_id_by_fb();
// $cognito_test->main_identity_provider();
// $cognito_test->main_identity();
// $cognito_test->fb_login();
// $cognito_test->google_login();

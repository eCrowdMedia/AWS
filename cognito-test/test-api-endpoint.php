<?php

require_once('../vendor/autoload.php');
use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\CognitoIdentityProvider\Exception\CognitoIdentityProviderException;

class cognito_test
{
    protected $client_id            = '';
    protected $client_secrect       = '';
    protected $user_pool_id         = '';
    protected $iam_access_key       = '';
    protected $iam_access_secret    = '';

    protected $region               = '';
    protected $version              = '';
    protected $grant_type           = '';
    protected $response_type        = '';
    protected $scope                = '';

    protected $redirect_uri         = '';
    protected $cognito_domain       = '';
    protected $code                 = '';
    protected $authorize            = 0;
    protected $login                = 0;
    protected $logout               = 0;
    protected $access_token         = '';
    protected $provider_client;

    /**
     * 預設值補充，動作操作判斷
     */
    public function __construct()
    {
        session_start();
        $this->env_data = include_once("test_api_env.php");

        $this->client_id            = $this->env_data['general']['client_id'];
        $this->client_secrect       = $this->env_data['general']['client_secret'];
        $this->user_pool_id         = $this->env_data['general']['user_pool_id'];
        $this->iam_access_key       = $this->env_data['general']['iam_access_key'];
        $this->iam_access_secret    = $this->env_data['general']['iam_access_secret'];
        $this->region               = $this->env_data['general']['region'];
        $this->version              = $this->env_data['general']['version'];
        $this->redirect_uri         = $this->env_data['endpoint']['redirect_uri'];
        $this->grant_type           = $this->env_data['endpoint']['grant_type'];
        $this->response_type        = $this->env_data['endpoint']['response_type'];
        $this->cognito_domain       = $this->env_data['endpoint']['cognito_domain'];

        if (isset($_GET['code']) and !empty($_GET['code'])) {
            $this->code = $_GET['code'];
        }
        if (isset($_GET['authorize']) and !empty($_GET['authorize'])) {
            $this->authorize = trim($_GET['authorize']);
        }
        if (isset($_GET['logout']) and !empty($_GET['logout'])) {
            $this->logout = trim($_GET['logout']);
        }
        if (isset($_GET['login']) and !empty($_GET['login'])) {
            $this->login = trim($_GET['login']);
        }
        if (isset($_GET['info']) and !empty($_GET['info'])) {
            $this->info = trim($_GET['info']);
        }
        if (isset($_GET['refresh']) and !empty($_GET['refresh'])) {
            $this->refresh = trim($_GET['refresh']);
        }

        if (isset($_GET['sdk']) and !empty($_GET['sdk'])) {
            $_SESSION['scope'] = 'aws.cognito.signin.user.admin';
        }
        if (isset($_GET['default']) and !empty($_GET['default'])) {
            $_SESSION['scope'] = 'openid';
        }

        $this->scope = $_SESSION['scope'];

        // 更新access_token
        $this->_refresh_accesss_token();
    }

    /**
     * 使用 endpoint 取得access_token 對 SDK 執行流程
     * aws 官方 Class CognitoIdentityProviderClient 支援 api function
     * https://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.CognitoIdentityProvider.CognitoIdentityProviderClient.html
     */
    public function main_sdk_use()
    {
        echo '<h2><b>call SDK function</b></h2>';
        try {
            $this->provider_client = new CognitoIdentityProviderClient([
                'version' => $this->version,
                'region' => $this->region,
                'credentials' => [
                    'key'    => $this->iam_access_key,
                    'secret' => $this->iam_access_secret,
                ],
            ]);

            // 取得單一user資訊
            $get_user_data = $this->_sdk_get_user();
            if (is_array($get_user_data)) {
                echo ' 已登入';
                echo "<a href='?sdk_logout=true' style='padding:10px;margin:0 0 0 3px;cursor:pointer;background-color:#efefef;border:1px solid #aaa;'>SIGN OUT</a>";
                echo '<div style="padding-left:20px;"><h3>單一使用者(getUser())</h3>';
                $this->output($get_user_data);
                echo '</div>';
            }

            // 列出所有user
            $list_user_data = $this->_sdk_list_user();
            if (is_array($list_user_data)) {
                echo '<div style="padding-left:20px;"><h3>所有使用者(listUsers())</h3>';
                $this->output($list_user_data);
                echo '</div>';
            }

            if(isset($_GET["sdk_logout"]) and $_GET["sdk_logout"] == 'true'){
                $this->_sdk_sign_out();
            }

        } catch (CognitoIdentityProviderException $e) {
            echo 'FAILED TO VALIDATE THE ACCESS TOKEN. ERROR = ' . $e->getMessage();
        }
    }

    /**
     * endpoint 執行流程
     */
    public function main()
    {
        echo '<h2><b>call endpoint function</b></h2>';

        // 點擊授權
        if ($this->authorize == 1) {
            $this->go_authorize();
        }

        // 點擊登入
        if ($this->login == 1) {
            $this->_user_login();
        }

        // 取得 user 資訊
        if (isset($this->access_token) and !empty($this->access_token)) {
            $user_info_result = $this->_get_user_info($this->access_token);

            $this->output($user_info_result);
        }

        // 登出
        if ($this->logout == 1) {
            $this->_user_logout();
        }

        // 使用 refresh token
        if ($this->refresh == 1) {
            $this->_refresh_token();
        }

    }

    /**
     * 轉址取得 code
     */
    public function go_authorize()
    {
        $query = http_build_query([
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => $this->response_type,
            'scope' => $this->scope
        ]);

        header('Location: '.$this->cognito_domain.'/oauth2/authorize?'.$query);
    }

    /**
     * 取得 access_token
     */
    private function _get_access_token(string $grant_type = '', string $refresh_token = '')
    {
        // 準備參數
        $query = [
            'code'          => $this->code,
            'grant_type'    => !empty($grant_type) ? $grant_type : $this->grant_type,
            'redirect_uri'  => $this->redirect_uri,
        ];

        if (!empty($refresh_token)) {
            $query['refresh_token'] = $refresh_token;
        }

        $url = $this->cognito_domain.'/oauth2/token';
        $method = 'POST';
        $header = [
            'Authorization: Basic '.base64_encode($this->client_id.':'.$this->client_secrect),
            'Content-Type: application/x-www-form-urlencoded'
        ];

        // 執行 curl
        $token_result = $this->_curl_operation($url, $method, http_build_query($query), $header);
        $all_token_decode = json_decode($token_result);

        // 寫到 session
        $this->_set_session([
            'get_all_token'         => $all_token_decode,
            'get_token_datetime'    => date('Y-m-d H:i:s'),
            'code'                  => $this->code,
            'access_token'          => $all_token_decode->access_token
        ]);

        return $all_token_decode;
    }

    /**
     * 取得 user 資訊
     */
    private function _get_user_info($access_token)
    {
        $url = $this->cognito_domain.'/oauth2/userInfo';
        $method = 'GET';
        $header = ['Authorization: Bearer '.$access_token];

        // 執行 curl
        $response = $this->_curl_operation($url, $method, [], $header);

        return json_decode($response);
    }

    /**
     * 使用者登入
     */
    private function _user_login()
    {
        $query = http_build_query([
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => $this->response_type,
            'scope' => $this->scope,
        ]);

        header('Location: '.$this->cognito_domain.'/login?'.$query);
    }

    /**
     * 使用者登出
     */
    private function _user_logout()
    {
        $this->_unset_session([
            'all_token',
            'get_all_token',
            'access_token',
            'get_token_datetime',
            'code',
        ]);

        $query = http_build_query([
            'client_id' => $this->client_id,
            'logout_uri' => $this->redirect_uri
        ]);

        header('Location: '.$this->cognito_domain.'/logout?'.$query);
    }

    /**
     * 輸出樣式
     */
    public function output($result)
    {
        echo "<pre>";var_export($result);
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
     * 更新 access_token
     */
    private function _refresh_accesss_token()
    {
        if (!empty($this->code) and $_SESSION['code'] != $this->code) {
            $this->_set_session([
                'get_all_token'         => $this->_get_access_token(),
                'get_token_datetime'    => date('Y-m-d H:i:s'),
                'code'                  => $this->code,
                'access_token'          => $_SESSION['access_token']
            ]);
        }

        $this->code = $_SESSION['code'];
        $this->access_token = $_SESSION['access_token'];
    }

    /**
     * 更新 all_token
     */
    private function _refresh_token()
    {
        $grant_type = 'refresh_token';
        $refresh_token = $_SESSION['get_all_token']->refresh_token;
        $result = $this->_get_access_token($grant_type, $refresh_token);

        $this->code = $_SESSION['code'];
        $this->access_token = $result->access_token;
    }

    /**
     * 頁面操作按鈕
     */
    public function operation_button()
    {
        echo '<a href="?authorize=1" style="float:left;">
            <div style="padding:10px;margin:0 3px 0 0;cursor:pointer;background-color:#efefef;border:1px solid #aaa;">get authorize</div>
            </a>';

        echo '<a href="?login=1" style="float:left;">
            <div style="padding:10px;margin:0 3px 0 0;cursor:pointer;background-color:#efefef;border:1px solid #aaa;">login</div>
            </a>';

        echo '<a href="?logout=1" style="float:left;">
            <div style="padding:10px;margin:0 3px 0 0;cursor:pointer;background-color:#efefef;border:1px solid #aaa;">logout</div>
            </a>';

        echo '<a href="?refresh=1" style="float:left;">
            <div style="padding:10px;margin:0 3px 0 0;cursor:pointer;background-color:#efefef;border:1px solid #aaa;">refresh Token</div>
            </a>';

        if ($this->scope == 'openid') {
            echo '<a href="?sdk=1" style="float:left;">
                <div style="padding:10px;margin:0 3px 0 0;cursor:pointer;background-color:#efefef;border:1px solid #aaa;">switch sdk type</div>
                </a>';
        } else {
            echo '<a href="?default=1" style="float:left;">
                <div style="padding:10px;margin:0 3px 0 0;cursor:pointer;background-color:#efefef;border:1px solid #aaa;">switch endpoint type</div>
                </a>';
        }

        echo '<div style="clear:both;"></div>';

        if (!empty($this->scope)) {
            echo '<div style="color:red;">current type: '.$this->scope.'</div>';
        }

        if (!isset($this->access_token)) {
            echo '尚未取得 user 資料';
        }

        if (count($_SESSION) > 0) {
            echo '<h3><b>SESSION DATA</b></h3>';
            $this->output($_SESSION);
            echo '<BR>';
        }

    }

    /**
     * curl 操作
     */
    private function _curl_operation($url, $method = 'GET', $post_data = '', $header = [])
    {
        $ch = curl_init();

        if ($method == 'POST' and !empty($post_data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $response = curl_exec($ch);
        curl_close ($ch);

        return $response;
    }

    /**
     * 使用 sdk 串接官方 api
     *  getUser()
     * https://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.CognitoIdentityProvider.CognitoIdentityProviderClient.html
     */
    private function _sdk_get_user()
    {
        $result = $this->provider_client->getUser([
            'AccessToken' => $this->access_token,
        ]);

        return $result['UserAttributes'];
    }

    /**
     * 使用 sdk 串接官方 api
     *  listUsers()
     * https://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.CognitoIdentityProvider.CognitoIdentityProviderClient.html
     */
    private function _sdk_list_user()
    {
        $result = $this->provider_client->listUsers([
            'UserPoolId' => $this->user_pool_id,
        ]);

        return $result['Users'];
    }

    /**
     * 使用 sdk 串接官方 api
     *  globalSignOut()
     * https://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.CognitoIdentityProvider.CognitoIdentityProviderClient.html
     */
    private function _sdk_sign_out()
    {
        $signout_result = $this->provider_client->globalSignOut([
            'AccessToken' => $this->access_token,
        ]);

        if ($signout_result['@metadata']['statusCode'] == 200) {
            $this->_user_logout();
        }
    }
}

$cognito_test = new cognito_test();
// 頁面操作按鈕
$cognito_test->operation_button();

$cognito_test->main();
$cognito_test->main_sdk_use();
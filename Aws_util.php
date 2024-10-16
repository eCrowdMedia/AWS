<?php
/**
 * Aws_util Class
 *
 * @package     Readmoo
 * @subpackage  AWS
 * @category    Libraries
 * @author      Willy
 * @link        https://readmoo.com
 */

class Aws_util
{
    private static $_s3_protocol = 's3://';
    private static $_priv_key = null;
    private $_CI = false;
    private $_config = false;
    private $_tasks = [];

    public function __construct($config = [])
    {
        $this->_CI =& get_instance();
        $this->_config = array_merge(
            [
                'eb_cli' => BASEPATH . 'cli.php'
            ],
            $this->_load_config('aws'),
            $config
        );
    }

    public function add_task($task)
    {
        if (is_string($task)) {
            $this->add_cmd($task);
        } elseif (isset($task['cmd'])) {
            $this->add_cmd($task['cmd']);
        } elseif (isset($task['cli'])) {
            $this->add_cli($task['cli']);
        } elseif (is_array($task)) {
            foreach ($task as $key => $value) {
                is_numeric($key) && $this->add_task($value);
            }
        }
        return $this;
    }

    public function add_cmd($cmd)
    {
        if (is_string($cmd)) {
            $this->_tasks[] = [
                'cmd' => $cmd
            ];
        } elseif (is_array($cmd)) {
            foreach ($cmd as $key => $value) {
                if (is_numeric($key) && is_string($value)) {
                    $this->_tasks[] = [
                        'cmd' => $value
                    ];
                }
            }
        }
        return $this;
    }

    public function add_cli($cli)
    {
        if (is_array($cli) && empty($cli['class'])) {
            return;
        }
        $this->_tasks[] = [
            'cli' => $cli
        ];
        return $this;
    }

    public function get_combined_cmd($tasks = [])
    {
        foreach ($tasks as $mode => $task) {
            $this->add_task($task);
        }
        $cmds = [];
        foreach ($this->_tasks as $task) {
            if (isset($task['cmd'])) {
                $cmd = $task['cmd'];
            } elseif (isset($task['cli'])) {
                $cmd = $this->_cli_to_cmd($task['cli']);
            } else {
                unset($cmd);
            }
            if (! empty($cmd)) {
                $cmds[] = $cmd;
            }
        }
        return implode(' && ', $cmds);
    }

    public function clear_task()
    {
        $this->_tasks = [];
        return $this;
    }

    public function s3_sync($source, $target, $options = false)
    {
        $args = [];
        $mode = 'sync';
        $dry_run = false;
        $recursive = false;
        $use_quote = true;
        $quite = true;
        $use_awss3cli = isset($this->_config['eb_aws_s3']);
        $no_mime_magic = ! $use_awss3cli;
        if (is_array($options)) {
            foreach ($options as $key => $value) {
                switch (strtolower($key)) {
                    case 'mode':
                        if (in_array($value, ['sync', 'get', 'put', 'cp', 'mv'])) {
                            $mode = $value;
                            if ($use_awss3cli && in_array($value, ['get', 'put'])) {
                                $mode = 'cp';
                            }
                        }
                        if ($mode == 'put' && str_starts_with($source, self::$_s3_protocol)) {
                            return false;
                        }
                        break;

                    case 'recursive':
                        $recursive = $value;
                        break;

                    /*case 'reducedredundancy':
                        if ($value) {
                            $args[] = $use_awss3cli ?
                                '--storage-class REDUCED_REDUNDANCY' :
                                '--rr';
                        }
                        break;*/

                    case 'public':
                        if ($value) {
                            $args[] = $use_awss3cli ? '--acl public-read' : '-P';
                        }
                        break;

                    case 'force':
                        if ($value && ! $use_awss3cli) {
                            $args[] = '-f';
                        }
                        break;

                    case 'delete':
                        if ($value) {
                            $args[] = $use_awss3cli ? '--delete' : '--delete-removed';
                        }
                        break;

                    case 'dry_run':
                        $dry_run = $value;
                        break;

                    case 'quote':
                        $use_quote = $value;
                        break;

                    case 'quite':
                        $quite = $value;
                        break;

                    case 'no-mime-magic':
                        $no_mime_magic = $value;
                        break;

                    case 'cache':
                        if ($value) {
                            $args[] = ($use_awss3cli ? '--cache-control ' : '--add-header=Cache-control:') . $value;
                        }
                        break;

                    case 'type':
                        if ($value) {
                            $args[] = sprintf(
                                '--content-type%s%s',
                                $use_awss3cli ? ' ' : '=',
                                $value
                            );
                        }
                        break;

                    default:
                        break;
                }
            }
        }

        if ($quite) {
            $args[] = $use_awss3cli ?
                '--quiet' :
                '-q';
        }

        if ($no_mime_magic) {
            $args[] = $use_awss3cli ? '' : '--no-mime-magic';
        }

        if ($recursive && (! $use_awss3cli or $mode != 'sync')) {
            $args[] = $use_awss3cli ? '--recursive' : '-r';
        }

        if ($use_awss3cli &&
            $mode == 'sync' &&
            preg_match('@(^.+\/)([^\/]+\*$)@', $source, $match)
        ) {
            $source = $match[1];
            $args[] = sprintf(
                '--exclude "*" --include "%s"',
                $match[2]
            );
        }

        $cmd = sprintf(
            empty($use_quote) ? '%s %s %s %s %s' : '%s %s %s "%s" "%s"',
            $use_awss3cli ? $this->_config['eb_aws_s3'] : $this->_config['cmd_s3cmd'],
            $mode,
            implode(' ', $args),
            $source,
            $target
        );

        if ($dry_run) {
            return $cmd;
        } else {
            $this->_CI->load->add_package_path(config_item('common_package'));
            $this->_CI->load->library('process_lib');
            $this->_CI->load->remove_package_path(config_item('common_package'));
            return $this->_CI->process_lib->execute($cmd);
        }
    }

    public function s3_del($s3_key, $dry_run = false)
    {
        if (empty($s3_key) or !str_starts_with($s3_key, self::$_s3_protocol)) {
            return false;
        }
        $this->_CI->load->add_package_path(config_item('common_package'));
        $this->_CI->load->library('process_lib');
        $this->_CI->load->remove_package_path(config_item('common_package'));
        $cmd = isset($this->_config['eb_aws_s3']) ?
            ($this->_config['eb_aws_s3'] . ' rm --recursive ') :
            ($this->_config['cmd_s3cmd'] . ' del -r ');
        $cmd .= $s3_key;
        return $dry_run ? $cmd : $this->_CI->process_lib->execute($cmd);
    }

    public function s3_key(array $params, $mode = 'ebook', $use_cf = false, $trailing_slash = true)
    {
        $segments = [
            self::$_s3_protocol . (
                $use_cf ?
                    $this->_config['cf_bucket'] :
                    $this->_config['s3_bucket']
            ),
            $mode
        ];
        switch ($mode) {
            case 'ebook':
            case 'book':
            case 'campaign':
            case 'doc':
            case 'api':
            case 'media_file':
            case 'user_reading_file':
            case 'feedback':
                $function = '_s3_key_' . $mode;
                $this->{$function}($segments, $params);
                break;

            case 'full':
            case 'preview':
            case 'manual':
                $this->_s3_key_ebook_file($mode, $segments, $params);
                break;

            case 'cover':
            case 'social/cover':
            case 'share/cover':
                $this->_s3_key_cover($mode, $segments, $params);
                break;

            case 'lcp':
                $this->_s3_key_epub($mode, $segments, $params);
                break;

            case 'epub_prefix':
                $this->_s3_key_epub_prefix($segments, $params);
                break;

            default:
                $this->_CI->load->helper('print');
                foreach ($this->_config['s3_key'] as $key => $value) {
                    if ($key == $mode or preg_match(sprintf('!%s!', $key), $mode)) {
                        (isset($value['validate']) && empty($value['validate'])) or
                        array_walk($params, function ($var): void {
                            if (empty($var)) {
                                throw new Exception('Invalid args.', 1);
                            }
                        });

                        if (isset($value['trailing_slash'])) {
                            $trailing_slash = $value['trailing_slash'];
                        }

                        $result = vnsprintf($value['format'], $params);
                        if (! empty($result)) {
                            $segments[] = $result;
                        }
                        break 2;
                    }
                }
                return null;
        }
        if ($trailing_slash) {
            $segments[] = '';
        }
        return implode('/', $segments);
    }

    public function get_config($key)
    {
        return $this->_config[$key];
    }

    public function set_config($key, $value)
    {
        $this->_config[$key] = $value;
    }

    public function get_signed_url(array $params)
    {
        $url = $params['url'] . '?';
        $params['url'] = str_replace('http://', 'http*://', $params['url']);
        [$use_custom_policy, $path, $secure, $policy] = $this->_presign_process($params);
        $signature = $this->_safe_base64_encode($this->_sign($policy));
        $query = [
            'Expires' => $params['less_than'],
            'Signature' => $signature,
            'Key-Pair-Id' => $this->get_config('cf_keypair_id'),
        ];
        return $url . http_build_query($query, null, ini_get('arg_separator.output'), PHP_QUERY_RFC3986);
    }

    public function set_signed_cookies(array $params)
    {
        [$use_custom_policy, $path, $secure, $policy] = $this->_presign_process($params);

        $use_custom_policy ?
            $this->_set_cookie(
                'Policy',
                $this->_safe_base64_encode($policy),
                $path,
                $secure
            ) :
            $this->_set_cookie(
                'Expires',
                $params['less_than'],
                $path,
                $secure
            );

        $this->_set_cookie(
            'Signature',
            $this->_safe_base64_encode($this->_sign($policy)),
            $path,
            $secure
        );

        $this->_set_cookie(
            'Key-Pair-Id',
            $this->get_config('cf_keypair_id'),
            $path,
            $secure
        );

        return true;
    }

    public function readfile($filename)
    {
        $args = [];
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $args['IfModifiedSince'] = $_SERVER['HTTP_IF_MODIFIED_SINCE'];
        }
        if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $args['IfNoneMatch'] = $_SERVER['HTTP_IF_NONE_MATCH'];
        }
        if (str_starts_with($filename, self::$_s3_protocol)) {
            if (! preg_match('|^s3://([^/]+)/(.+)$|', $filename, $match)) {
                return header('HTTP/1.0 404 Not Found');
            }
            class_exists('Aws_lib') or $this->_CI->load->library('aws/aws_lib');
            $result = $this->_CI->aws_lib->headObject($match[1], $match[2], $args);
            if (empty($result)) {
                return header('HTTP/1.0 404 Not Found');
            }
            $args['ETag'] = $result->get('ETag');
            $args['LastModified'] = $result->get('LastModified') ? $result->get('LastModified')->format('D, d M Y H:i:s \G\M\T') : null;
            $args['ContentType'] = $result->get('ContentType');
            $args['ContentLength'] = $result->get('ContentLength');
        } else {
            $timestamp = filemtime($filename);
            $args['ETag'] = '"' . md5('traditional chinese' . $timestamp) . '"';
            $args['LastModified'] = gmdate('D, d M Y H:i:s \G\M\T', $timestamp);
            $args['ContentType'] = null;
            $args['ContentLength'] = filesize($filename);
        }
        if ((empty($args['IfNoneMatch']) || $args['ETag'] == $args['IfNoneMatch']) &&
            (isset($args['IfModifiedSince']) && $args['LastModified'] == $args['IfModifiedSince'])
        ) {
            return header('HTTP/1.1 304 Not Modified');
        }

        $this->_CI->load->helper('file');

        ob_start();
        header('Cache-Control: private, max-age=31536000, pre-check=31536000');
        header('Last-Modified: ' . $args['LastModified']);
        header('ETag: ' . $args['ETag']);
        header('Pragma: public');
        header('Content-Type: ' . get_mime_by_extension($filename, $args['ContentType']));
        header('Content-Length: ' . $args['ContentLength']);
        ob_clean();
        ob_end_flush();

        readfile($filename);
        exit; // Prevent CodeIgniter's output buffer.
    }

    public function sms($phone, $message)
    {
        class_exists('Aws_lib') or $this->_CI->load->library('aws/aws_lib');
        return $this->_CI->aws_lib->publish([
            'PhoneNumber' => $phone,
            'Message' => $message,
        ]);
    }

    public function publish($topic, $message, $subject = null)
    {
        class_exists('Aws_lib') OR $this->_CI->load->library('aws/aws_lib');
        $prefix = $this->_config['sns_topic_prefix'];
        return $this->_CI->aws_lib->publish([
            'TopicArn' => (str_starts_with($topic, $prefix) ? '' : $prefix) . $topic,
            'Subject' => $subject,
            'Message' => $message,
        ]);
    }

    public function subscribe($endpoint, $protocol, $topic)
    {
        class_exists('Aws_lib') OR $this->_CI->load->library('aws/aws_lib');
        $prefix = $this->_config['sns_topic_prefix'];
        return $this->_CI->aws_lib->subscribe(
            $endpoint,
            $protocol,
            (str_starts_with($topic, $prefix) ? '' : $prefix) . $topic
        );
    }

    public function s3_list($bucket, $prefix, $list_filename, array $filters = ['sed' => '/Thumbs\.db|\.DS_STORE/d'], $recursive = true)
    {
        $this->_CI->load->add_package_path(config_item('common_package'));
        $this->_CI->load->library('process_lib');
        $this->_CI->load->remove_package_path(config_item('common_package'));
        $cmd = sprintf(
            'aws s3 ls %s--summarize s3://%s/%s/ ',
            $recursive ? '--recursive ' : null,
            $bucket,
            $prefix
        );

        foreach ($filters as $key => $pattern) {
            $cmd .= sprintf(
                '| %s "%s" ',
                $key,
                $pattern
            );
        }
        $cmd .= '> ' . $list_filename;

        set_time_limit(0);
        $credentials = $this->_config['aws_config']['credentials'] ?? null;
        isset($credentials['key']) && putenv('AWS_ACCESS_KEY_ID=' . $credentials['key']);
        isset($credentials['secret']) && putenv('AWS_SECRET_ACCESS_KEY=' . $credentials['secret']);
        $this->_CI->process_lib->execute($cmd);
        return $list_filename;
    }

    public function notify_event($uuid, $data)
    {
        class_exists('Aws_lib') or $this->_CI->load->library('aws/aws_lib');
        $result = $this->_CI->aws_lib->queryScan([
            'TableName' => $this->_config['event_tablename'],
            'ProjectionExpression' => 'connectionId, eventId',
            'FilterExpression' => 'contains(events, :eventId)',
            'ExpressionAttributeValues' => [
                ':eventId' => ['S' => $uuid]
            ],
        ]);
        if (!isset($result['items'])) {
            throw new Exception('No event DynamoDB found.');
        } elseif ($result['count'] == 0) {
            return;
        }

        return array_map(
            fn($item) => $this->_CI->aws_lib->postToConnection(
                current($item['connectionId']),
                $data
            ),
            $result['items']
        );
    }

    public function list_topics($prefix = null)
    {
        static $next_token = null;
        $result = [];
        if (!empty($prefix) &&
            !str_starts_with($prefix, $this->_config['sns_topic_prefix'])
        ) {
            $prefix = $this->_config['sns_topic_prefix'] . $prefix;
        }
        class_exists('Aws_lib') OR $this->_CI->load->library('aws/aws_lib');
        do {
            $list = $this->_CI->aws_lib->listTopics($next_token);
            if (empty($prefix)) {
                empty($list['Topics']) or array_push(
                    $result,
                    array_column($list['Topics'], 'TopicArn'))
                ;
            } else {
                empty($list['Topics']) or array_walk(
                    $list['Topics'],
                    function($topic) use (&$result, $prefix): void {
                        if (str_starts_with($topic['TopicArn'], $prefix)) {
                            $result[] = $topic['TopicArn'];
                        }
                    }
                );
            }
            $next_token = $list['NextToken'] ?? null;
        } while ($next_token != null);
        return $result;
    }

    public function list_subscriptions_by_topic($topic, $next_token = null)
    {
        class_exists('Aws_lib') OR $this->_CI->load->library('aws/aws_lib');
        $prefix = $this->_config['sns_topic_prefix'];
        $topic_arn = (str_starts_with($topic, $prefix) ? '' : $prefix) . $topic;
        if ($next_token === true) {
            $result = [];
            $next_token = null;
        }
        do {
            $list = $this->_CI->aws_lib->listSubscriptionsByTopic($topic_arn, $next_token);
            if (!isset($result)) {
                return $list;
            }
            array_push(
                $result,
                array_column($list['Subscriptions'], null, 'SubscriptionArn')
            );
            $next_token = $list['NextToken'] ?? null;
        } while ($next_token != null);
        return $result;
    }

    private function _load_config($file)
    {
        $config = $this->_CI->config->item($file);
        if (empty($config)) {
            $this->_CI->config->load($file, true);
        }
        $config = $this->_CI->config->item($file);
        return empty($config) ? [] : $config;
    }

    private function _presign_process(array &$params)
    {
        if (empty($params['url']) or
            ! preg_match('|^(http[s\*]?):\/\/([^\/]+)(\/.*)$|', $params['url'], $match)
        ) {
            throw new Exception('Invalid parameters, no url found.', 400);
        }
        $use_custom_policy = strpos($match[3], '*') > 0;
        $path = $use_custom_policy ?
            substr($match[3], 0, strpos($match[3], '*')) :
            $match[3];
        $secure = $match[1] != 'http';
        if (empty($params['less_than'])) {
            $params['less_than'] = time() + config_item('sess_expiration');
        }
        return [
            $use_custom_policy,
            $path,
            $secure,
            $use_custom_policy ?
                $this->_get_custom_policy($params) :
                $this->_get_canned_policy($params)
        ];
    }

    private function _get_custom_policy(array $params)
    {
        $policy = $this->_get_policy_statement($params);
        if (isset($params['greater_than'])) {
            $policy['Statement']['Condition']['DateGreaterThan'] = ['AWS:EpochTime' => $params['greater_than']];
        }
        if (isset($params['ip'])) {
            $policy['Statement']['Condition']['IpAddress'] = $params['ip'];
        }
        return json_encode($policy, JSON_UNESCAPED_SLASHES);
    }

    private function _get_canned_policy(array $params)
    {
        return json_encode(
            $this->_get_policy_statement($params),
            JSON_UNESCAPED_SLASHES
        );
    }

    private function _get_policy_statement(array $params)
    {
        return [
            'Statement' => [[
                'Resource' => $params['url'],
                'Condition' => [
                    'DateLessThan' => [
                        'AWS:EpochTime' => $params['less_than']
                    ]
                ]
            ]]
        ];
    }

    private function _safe_base64_encode($content)
    {
        return str_replace(['+', '=', '/'], ['-', '_', '~'], base64_encode($content));
    }

    private function _sign($data)
    {
        if (empty(self::$_priv_key)) {
            if (!is_readable($this->get_config('cf_pk_pathname'))) {
                return null;
            }
            self::$_priv_key = openssl_get_privatekey('file://' . $this->get_config('cf_pk_pathname'));
        }
        $signature = null;
        openssl_sign($data, $signature, self::$_priv_key);

        return $signature;
    }

    private function _set_cookie($name, $value, $path, $secure = true)
    {
        setcookie(
            'CloudFront-' . $name,
            $value,
            ['expires' => 0, 'path' => $path, 'domain' => $_SERVER['DOMAIN'], 'secure' => $secure, 'httponly' => true]
        );
    }

    private function _cli_to_cmd($cli)
    {
        $command = $this->_config['eb_cli'];
        if (is_string($cli)) {
            $command .= ' ' . $cli;
        } elseif (isset($cli['class'])) {
            if (! empty($cli['path'])) {
                $command .= ' ' . implode(' ', (array)$cli['path']);
            }
            $command .= ' ' . $cli['class'];
            $command .= ' ' . ((empty($cli['method']) or ! is_string($cli['method'])) ? 'index' : $cli['method']);
            if (isset($cli['parameters'])) {
                $command .= ' ' . (is_array($cli['parameters']) ? implode(' ', $cli['parameters']) : $cli['parameters']);
            }
        } else {
            return false;
        }
        return $command;
    }

    private function _s3_key_epub_prefix(array &$segments, array $params)
    {
        $segments[1] = 'ebook';

        if (!isset($params['path'])) {
            return;
        }

        if (preg_match('|^[et]/|', $params['path'])) {
            $segments = [
                sprintf(
                    '%sepub.readmoo.%s',
                    self::$_s3_protocol,
                    ENVIRONMENT == 'production' ? 'com' : 'tw'
                )
            ];
        }
    }

    private function _s3_key_ebook(array &$segments, array $params)
    {
        if (isset($params['file'])) {
            $file = $params['file'];
            if (empty($file['manifestation_id']) or
                empty($file['sn'])
            ) {
                throw new Exception('Invalid parameters, no manifestation_id');
            }
            $segments[] = $file['manifestation_id'] % 1000;
            $segments[] = $file['manifestation_id'];
            $segments[] = $file['sn'];
            if (empty($file['version']) or
                empty($file['setting'])
            ) {
                return true;
            }
            $setting = is_string($file['setting']) ?
                json_decode($file['setting'], true) :
                $file['setting'];

            if (preg_match('|^[et]/|', $setting['path'])) {
                $segments = [
                    sprintf(
                        '%sepub.readmoo.%s',
                        self::$_s3_protocol,
                        ENVIRONMENT == 'production' ? 'com' : 'tw'
                    ),
                    rtrim($setting['path'], '/'),
                ];
            } else {
                $segments[] = sprintf(
                    '%d_%d',
                    $file['version'],
                    $setting['revision'] ?? 0
                );
            }
        }
        // manifestataion
        elseif (isset($params['manifestation'])) {
            if (empty($params['manifestation']['sn'])) {
                throw new Exception('Invalid parameters, no manifestation_id');
            }
            $segments[] = $params['manifestation']['sn'] % 1000;
            $segments[] = $params['manifestation']['sn'];
        }
    }

    private function _s3_key_book(array &$segments, array $params)
    {
        $segments[] = empty($params['mode']) ? 'preview' : $params['mode'];
        if (! empty($params['readmoo_id'])) {
            $segments[] = $params['readmoo_id'];
        }
    }

    private function _s3_key_ebook_file($mode, array &$segments, array $params)
    {
        if (empty($params['file'])) {
            throw new Exception('Invalid parameters, no file found');
        }
        array_pop($segments);
        $segments[] = 'ebook';
        $file = $params['file'];
        if (empty($file['manifestation_id']) or
            empty($file['sn'])) {
            throw new Exception('Invalid parameters, no manifestation_id');
        }
        $segments[] = $file['manifestation_id'] % 1000;
        $segments[] = $file['manifestation_id'];
        $segments[] = $file['sn'];
        if (empty($file['version']) or
            empty($file['setting'])) {
            throw new Exception('Invalid parameters, file is incomplete');
        }
        $setting = is_string($file['setting']) ?
            json_decode($file['setting'], true) :
            $file['setting'];
        $segments[] = $file['version'] . '_' . ($setting['revision'] ?? '0');
        $segments[] = $mode;
    }

    private function _s3_key_cover($mode, array &$segments, array $params)
    {
        function_exists('id_encrypt') or $this->_CI->load->helper('id_encrypt');
        switch ($mode) {
            case 'cover':
                if (! empty($params['manifestation']['sn'])) {
                    $encoded_id = id_encode($params['manifestation']['sn']);
                }
                break;
            case 'social/cover':
                if (! empty($params['work_id'])) {
                    $encoded_id = id_encode($params['work_id']);
                }
                break;
            case 'share/cover':
                if (! empty($params['manifestation_id'])) {
                    $encoded_id = id_encode($params['manifestation_id']);
                }
                break;
            default:
                break;
        }
        if (isset($params['encoded_id'])) {
            $encoded_id = $params['encoded_id'];
        }
        if (isset($encoded_id) && strlen($encoded_id) > 2) {
            $segments[] = substr_replace($encoded_id, '/', 2, 0);
        } else {
            throw new Exception('Invalid parameters, no proper id found');
        }
    }

    private function _s3_key_campaign(array &$segments, array $params)
    {
        if (empty($params['name'])) {
            throw new Exception('Invalid parameters, no campaign name');
        }
        $segments = [
            self::$_s3_protocol . 'readmoo-campaign',
            'campaign',
            $params['name']
        ];
        if (! empty($params['path'])) {
            $segments[] = $params['path'];
        }
    }

    private function _s3_key_doc(array &$segments, array $params)
    {
        $segments = [
            self::$_s3_protocol . 'readmoo-doc-' . ENVIRONMENT,
            'd',
        ];
        if (isset($params['md'], $params['sha'])) {
            $segments[] = substr_replace(substr_replace($params['md'], '/', 8, 0), '/', 4, 0) . substr($params['sha'], -2);
        } elseif (isset($params['key'])) {
            $segments[] = $params['key'];
        }
    }

    private function _s3_key_api(array &$segments, array $params)
    {
        array_unshift($params, sprintf(
            '%sapi.readmoo.%s',
            self::$_s3_protocol,
            ENVIRONMENT == 'production' ? 'com' : 'tw'
        ));
        $segments = $params;
    }

    private function _s3_key_epub($mode, array &$segments, array $params)
    {
        $segments = [
            sprintf(
                '%sepub.readmoo.%s',
                self::$_s3_protocol,
                ENVIRONMENT == 'production' ? 'com' : 'tw'
            ),
            $mode[0],
        ];
        if (!empty($params['content-id'])) {
            $segments[] = substr_replace(substr_replace($params['content-id'], '/', 8, 0), '/', 4, 0) . '.epub';
        }
    }

    private function _s3_key_media_file(array &$segments, array $params)
    {
        $segments = [
            self::$_s3_protocol . 'file' . $_SERVER['DOMAIN'],
            $params['prefix'][0] ?? 'f',
        ];
        if (isset($params['md5'], $params['sha256'])) {
            $segments[] = substr_replace(substr_replace($params['md5'], '/', 8, 0), '/', 4, 0) . substr($params['sha256'], -2);
        } elseif (isset($params['key'])) {
            $segments[] = $params['key'];
        }
    }

    private function _s3_key_feedback(array &$segments, array $params)
    {
        $segments = [
            self::$_s3_protocol . 'feedback' . $_SERVER['DOMAIN'],
            $params['prefix'][0] ?? 'f',
        ];
        if (isset($params['md5'], $params['sha256'])) {
            $segments[] = substr_replace(substr_replace($params['md5'], '/', 8, 0), '/', 4, 0) . substr($params['sha256'], -2);
        } elseif (isset($params['key'])) {
            $segments[] = $params['key'];
        }
    }

    private function _s3_key_user_reading_file(array &$segments, array $params)
    {
        function_exists('id_encrypt') or $this->_CI->load->helper('id_encrypt');
        $segments = [
            self::$_s3_protocol . 'file' . $_SERVER['DOMAIN'],
            $params['prefix'][0] ?? 'u',
            $encoded_user_id = id_encode($params['user_id']),
        ];
        foreach (['reading_id', 'file_id'] as $key) {
            $value = intval($params[$key]);
            if ($value >> 32) {
                $format = 'Q';
            } elseif ($value >> 16) {
                $format = 'L';
            } elseif ($value >> 8) {
                $format = 'S';
            } else {
                $format = 'C';
            }
            $binary = pack($format, $value);
            $segments[] = str_replace(
                ['+', '/'],
                ['-', '_'],
                rtrim(base64_encode($binary), '=')
            );
        }
    }
}
// END Aws_util Class
/* End of file Aws_util.php */

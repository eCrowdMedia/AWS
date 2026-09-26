<?php

/**
 * Aws_lib Class.
 *
 * @category    Libraries
 *
 * @author      Willy
 *
 * @link        https://readmoo.com
 */
use Aws\CloudFront\Enum\ViewerProtocolPolicy;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\CloudFrontKeyValueStore\Exception\CloudFrontKeyValueStoreException;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use Aws\Ses\Exception\SesException;
use Aws\Sqs\Exception\SqsException;
use Aws\Batch\Exception\BatchException;
use Aws\Signature\SignatureV4;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

use Ecrowdmedia\Aws\Exception\AwsCredentialsUnavailable;
use Ecrowdmedia\Aws\Exception\AwsFailureCategory;
use Ecrowdmedia\Aws\Exception\AwsMessageFormat;
use Ecrowdmedia\Aws\Exception\AwsOperationException;
use Ecrowdmedia\Aws\Exception\DynamoDbConditionFailed;
use Ecrowdmedia\Aws\Exception\DynamoDbRequestRejected;
use Ecrowdmedia\Aws\Exception\DynamoDbUnavailable;

// 本套件型別為 codeigniter-library，安裝時整個目錄被 composer/installers 複製到
// CI 的 libraries 路徑下、**不在 vendor/ 內**，因此沒有 PSR-4 autoload 可用。
// 上面那些例外型別必須在此明確載入，否則 catch 會靜默不匹配（PHP 對無法解析的
// catch 型別不會報錯，只是永遠不匹配——這正是 1.39.12 之前 DynamoDbException
// 那些 catch 全是死碼的原因）。
require_once __DIR__ . '/Aws_exceptions.php';

class Aws_lib
{
    private $_CI = null;
    private $_sdk = null;
    private $_cfIdentity = null;
    private $_config = null;
    private $_client_pool = [];

    public function __construct(array $config = [])
    {
        if (empty($config)) {
            $this->_CI = &get_instance();
            $aws_config = $this->_CI->config->item('aws');
            if (empty($aws_config)) {
                $this->_CI->config->load('aws', true);
            }
            $this->_config = $this->_CI->config->item('aws_config', 'aws');
        } else {
            $this->_config = $config;
        }

        $this->_sdk = new Aws\Sdk($this->_config);
    }

    public function isBucketDnsCompatible(string $bucket_name)
    {
        try {
            return $this->get_client('S3')->isBucketDnsCompatible($bucket_name) ? true : false;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function doesBucketExist(string $bucket_name)
    {
        try {
            return $this->get_client('S3')->doesBucketExist($bucket_name) ? true : false;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model createBucket(array $args = array()) {@command S3 CreateBucket}
     */
    public function createBucket(string $bucket_name)
    {
        if (!$this->isBucketDnsCompatible($bucket_name)) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
        if ($this->doesBucketExist($bucket_name)) {
            return false;
        }
        try {
            $this->get_client('S3')->createBucket([
                'Bucket' => $bucket_name,
                'ACL' => 'public-read',
            ]);

            return true;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model headBucket(array $args = array()) {@command S3 HeadBucket}
     */
    public function headBucket(string $bucket_name)
    {
        try {
            return $this->get_client('S3')->headBucket([
                'Bucket' => $bucket_name,
            ]);
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model headObject(array $args = array()) {@command S3 HeadObject}
     */
    public function headObject(string $bucket_name, string $key, array $args = [])
    {
        try {
            $args['Bucket'] = $bucket_name;
            $args['Key'] = $key;

            return $this->get_client('S3')->headObject($args);
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model putBucketPolicy(array $args = array()) {@command S3 PutBucketPolicy}
     */
    public function putBucketPolicy(string $bucket_name)
    {
        if ($this->doesBucketExist($bucket_name)) {
            try {
                $this->get_client('S3')->putBucketPolicy([
                    'Bucket' => $bucket_name,
                    'Policy' => $this->_return_bucket_policy($bucket_name),
                ]);

                return true;
            } catch (S3Exception $e) {
                return empty($this->_config['debug']) ? false : $e->getMessage();
            }
        } else {
            return false;
        }
    }

    /**
     * @method Model deleteBucket(array $args = array()) {@command S3 DeleteBucket}
     */
    public function deleteBucket(string $bucket_name)
    {
        try {
            $this->get_client('S3')->deleteBucket([
                'Bucket' => $bucket_name,
            ]);

            return true;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function doesObjectExist(string $bucket_name, string $key)
    {
        try {
            return $this->get_client('S3')->doesObjectExist($bucket_name, $key) ? true : false;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model getObject(array $args = [])
     */
    public function getObject(string $bucket_name, string $key, array $options = [])
    {
        try {
            $options = [
                'Bucket' => $bucket_name,
                'Key' => $key,
            ] + $options;

            return $this->get_client('S3')->getObject($options);
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model putObject(array $args = array()) {@command S3 PutObject}
     */
    public function putObject(string $bucket_name, string $key, $source, array $options = [])
    {
        try {
            $options = [
                'Bucket' => $bucket_name,
                'Key' => $key,
            ] + $options;
            if (empty($options['SourceFile']) && empty($options['Body'])) {
                $options[is_file($source) ? 'SourceFile' : 'Body'] = $source;
            }
            if (empty($options['ContentType'])) {
                if (isset($options['SourceFile'])) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $options['ContentType'] = finfo_file($finfo, $options['SourceFile']);
                } else {
                    function_exists('get_mime_by_extension') or $this->_CI->load->helper('file');
                    $options['ContentType'] = get_mime_by_extension($key);
                }
            }

            return $this->get_client('S3')->putObject($options);
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model copyObject(array $args = array()) {@command S3 CopyObject}
     */
    public function copyObject(string $bucket_name, string $key, $source, array $options = [])
    {
        try {
            $options = [
                'Bucket' => $bucket_name,
                'Key' => $key,
                'CopySource' => implode('/', array_map('rawurlencode', explode('/', $source))),
            ] + $options;

            return $this->get_client('S3')->copyObject($options);
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model deleteObject(array $args = array()) {@command S3 DeleteObject}
     */
    public function deleteObject(string $bucket_name, string $s3key)
    {
        try {
            $this->get_client('S3')->deleteObject([
                'Bucket' => $bucket_name,
                'Key' => $s3key,
            ]);

            return true;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model deleteObjects(array $args = array()) {@command S3 DeleteObjects}
     */
    public function deleteObjects(string $bucket_name, $objects)
    {
        try {
            $this->get_client('S3')->deleteObjects([
                'Bucket' => $bucket_name,
                'Delete' => [
                    'Objects' => $objects
                ]
            ]);

            return true;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method int deleteMatchingObjects($bucket, $prefix = '', $regex = '', array $options = array()) {@command S3 DeleteMatchingObjects}
     */
    public function deleteMatchingObjects(string $bucket_name, string $prefix = '', string $regex = '', array $options = [])
    {
        try {
            return $this->get_client('S3')->deleteMatchingObjects($bucket_name, $prefix, $regex, $options);
        } catch (RuntimeException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function registerStreamWrapper()
    {
        return $this->get_client('S3')->registerStreamWrapper();
    }

    /**
     * @method Model listObjects(array $args = array()) {@command S3 ListObjects}
     */
    public function listObjects(string $bucket_name, string $prefix = '', int $max_keys = 1000)
    {
        try {
            return $this->get_client('S3')->listObjects([
                'Bucket' => $bucket_name,
                'Prefix' => $prefix,
                'MaxKeys' => $max_keys,
            ]);
        } catch (RuntimeException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model listObjectsV2(array $args = array()) {@command S3 ListObjectsV2}
     */
    public function listObjectsV2(
        string $bucket,
        string $prefix = '',
        int $max_keys = 1000
    ): Generator {
        $continuationToken = null;
        do {
            $result = $this->get_client('S3')->listObjectsV2([
                'Bucket' => $bucket,
                'Prefix' => $prefix,
                'MaxKeys' => $max_keys,
                'ContinuationToken' => $continuationToken,
            ]);
            foreach ($result['Contents'] as $content) {
                yield $content;
            }
            $continuationToken = $result['NextContinuationToken'] ?? null;
        } while ($result['IsTruncated']);
    }

    /**
     * Create a pre-signed URL for a request
     *
     * @param string $method get, head, put, delete
     * @param int|string|\DateTime $expires The time at which the URL should expire.
     *                                      This can be a Unix timestamp,
     *                                      a PHP DateTime object,
     *                                      or a string that can be evaluated by strtotime
     * @return string
     */
    public function createPresignedUrl(string $method, string $bucket, string $key, $expires, array $options = [])
    {
        try {
            $s3_client = $this->get_client('S3');
            $command = $s3_client->getCommand(
                ucfirst(strtolower($method)). 'Object',
                ['Bucket' => $bucket, 'Key' => $key] + $options
            );
            $request = $s3_client->createPresignedRequest($command, $expires);
            return (string)$request->getUri();
        } catch (RuntimeException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * [createDistribution description].
     *
     * @param [type] $bucket_name [description]
     * @param [type] $domain_name [description]
     *
     * @return [type] [description]
     */
    public function createDistribution(string $bucket_name, string $domain_name)
    {
        try {
            $return = $this->get_client('CloudFront')->createDistribution($this->_return_distribution_config_array($bucket_name, $domain_name, true));

            return $return->toArray();
        } catch (CloudFrontException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function disableDistribution(string $cfID)
    {
        try {
            $cf_client = $this->get_client('CloudFront');
            $getConfig = $cf_client->getDistributionConfig(['Id' => $cfID]);
            $got_config_array = $getConfig->toArray();
            $config_array = $got_config_array;
            $config_array['Enabled'] = false;
            $config_array['Id'] = $cfID;
            $config_array['IfMatch'] = $got_config_array['ETag'];
            $config_array['Logging'] = [
                'Enabled' => false,
                'Bucket' => '',
                'Prefix' => '',
            ];
            unset($config_array['ETag'], $config_array['RequestId']);
            $cf_client->updateDistribution($config_array);
        } catch (CloudFrontException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function deleteDistribution(string $cfID)
    {
        try {
            $cf_client = $this->get_client('CloudFront');
            $getDistribution = $cf_client->getDistribution(['Id' => $cfID]);
            $got_distribution_array = $getDistribution['Distribution'];
            if ($got_distribution_array['Status'] == 'Deployed' and $got_distribution_array['DistributionConfig']['Enabled'] == false) {
                $cf_client->deleteDistribution(['Id' => $cfID, 'IfMatch' => $getDistribution['ETag']]);
                return true;
            } else {
                return false;
            }
        } catch (CloudFrontException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model getDistribution(array $args = array()) {@command CloudFront GetDistribution}
     */
    public function getDistribution(string $cfID)
    {
        try {
            return $this->get_client('CloudFront')->getDistribution(['Id' => $cfID]);
        } catch (CloudFrontException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model listDistributions(array $args = array()) {@command CloudFront ListDistributions}
     */
    public function listDistributions($cname = false)
    {
        try {
            $distributions = $this->get_client('CloudFront')->listDistributions();
            $distributions = $distributions['DistributionList'];
            $result = [];
            if ($cname) {
                foreach ($distributions['Items'] as $distribution) {
                    if ($distribution['Aliases']['Quantity'] > 1) {
                        foreach ($distribution['Aliases']['Items'] as $alias) {
                            if (preg_match(sprintf('/%s$/', $cname), $alias)) {
                                $result[] = $distribution;
                                break;
                            }
                        }
                    }
                }
            } else {
                $result = $distributions['Items'];
            }

            return $result;
        } catch (CloudFrontException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model createInvalidation(array $args = array()) {@command CloudFront CreateInvalidation}
     */
    public function createInvalidation(string $cfID, array $paths, $caller_reference = false)
    {
        try {
            if (empty($paths)) {
                return false;
            } elseif (empty($caller_reference)) {
                $caller_reference = rtrim(base64_encode(sha1(implode("\x01", $paths).date('Y-m-d H:i:s'))), '=');

                return $this->get_client('CloudFront')->createInvalidation([
                    'DistributionId' => $cfID,
                    'InvalidationBatch' => [
                        'Paths' => [
                            'Quantity' => count($paths),
                            'Items' => $paths,
                        ],
                        'CallerReference' => $caller_reference,
                    ],
                ]);
            }
        } catch (CloudFrontException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }
    /**
     * Client to interact with Amazon CloudFront.
     *
     * @method Model createCloudFrontOriginAccessIdentity(array $args = array()) {@command CloudFront CreateCloudFrontOriginAccessIdentity}
     * @method Model createStreamingDistribution(array $args = array()) {@command CloudFront CreateStreamingDistribution}
     * @method Model deleteCloudFrontOriginAccessIdentity(array $args = array()) {@command CloudFront DeleteCloudFrontOriginAccessIdentity}
     * @method Model deleteStreamingDistribution(array $args = array()) {@command CloudFront DeleteStreamingDistribution}
     * @method Model getCloudFrontOriginAccessIdentity(array $args = array()) {@command CloudFront GetCloudFrontOriginAccessIdentity}
     * @method Model getCloudFrontOriginAccessIdentityConfig(array $args = array()) {@command CloudFront GetCloudFrontOriginAccessIdentityConfig}
     * @method Model getDistributionConfig(array $args = array()) {@command CloudFront GetDistributionConfig}
     * @method Model getInvalidation(array $args = array()) {@command CloudFront GetInvalidation}
     * @method Model getStreamingDistribution(array $args = array()) {@command CloudFront GetStreamingDistribution}
     * @method Model getStreamingDistributionConfig(array $args = array()) {@command CloudFront GetStreamingDistributionConfig}
     * @method Model listCloudFrontOriginAccessIdentities(array $args = array()) {@command CloudFront ListCloudFrontOriginAccessIdentities}
     * @method Model listInvalidations(array $args = array()) {@command CloudFront ListInvalidations}
     * @method Model listStreamingDistributions(array $args = array()) {@command CloudFront ListStreamingDistributions}
     * @method Model updateCloudFrontOriginAccessIdentity(array $args = array()) {@command CloudFront UpdateCloudFrontOriginAccessIdentity}
     * @method Model updateStreamingDistribution(array $args = array()) {@command CloudFront UpdateStreamingDistribution}
     * @method waitUntilStreamingDistributionDeployed(array $input) The input array uses the parameters of the GetStreamingDistribution operation and waiter specific settings
     * @method waitUntilDistributionDeployed(array $input) The input array uses the parameters of the GetDistribution operation and waiter specific settings
     * @method waitUntilInvalidationCompleted(array $input) The input array uses the parameters of the GetInvalidation operation and waiter specific settings
     * @method ResourceIteratorInterface getListCloudFrontOriginAccessIdentitiesIterator(array $args = array()) The input array uses the parameters of the ListCloudFrontOriginAccessIdentities operation
     * @method ResourceIteratorInterface getListDistributionsIterator(array $args = array()) The input array uses the parameters of the ListDistributions operation
     * @method ResourceIteratorInterface getListInvalidationsIterator(array $args = array()) The input array uses the parameters of the ListInvalidations operation
     * @method ResourceIteratorInterface getListStreamingDistributionsIterator(array $args = array()) The input array uses the parameters of the ListStreamingDistributions operation
     */

    /*
     * @method \Aws\Result describeKeyValueStore(array $args = [])
     */
    public function describeKeyValueStore(string $kvs): Aws\Result|bool|string
    {
        try {
            $params = [
                'KvsARN' => $kvs,
            ];
            $result = $this->get_client('CloudFrontKeyValueStore')->describeKeyValueStore($params);

            return $result;
        } catch (CloudFrontKeyValueStoreException $e) {
            return match (true) {
                $e->getStatusCode() == 404 => null,
                empty($this->_config['debug']) => false,
                default => $e->getMessage(),
            };
        }
    }

    /*
     * @method \Aws\Result getKey(array $args = [])
     */
    public function getKey(string $key, string $kvs): ?string
    {
        try {
            $params = [
                'Key' => $key,
                'KvsARN' => $kvs,
            ];
            $result = $this->get_client('CloudFrontKeyValueStore')->getKey($params);

            return $result->get('Value');
        } catch (CloudFrontKeyValueStoreException $e) {
            return match (true) {
                $e->getStatusCode() == 404 => null,
                empty($this->_config['debug']) => false,
                default => $e->getMessage(),
            };
        }
    }

    /*
     * @method \Aws\Result putKey(array $args = [])
     */
    public function putKey(
        string $kvs,
        string $ifMatch,
        string $key,
        string $value
    ): Aws\Result|bool|string {
        try {
            $params = [
                'IfMatch' => $ifMatch,
                'Key' => $key,
                'KvsARN' => $kvs,
                'Value' => $value,
            ];
            $result = $this->get_client('CloudFrontKeyValueStore')->putKey($params);

            return $result;
        } catch (CloudFrontKeyValueStoreException $e) {
            return match (true) {
                $e->getStatusCode() == 404 => false,
                empty($this->_config['debug']) => false,
                default => $e->getMessage(),
            };
        }
    }

    /*
     * @method \Aws\Result listKeys(array $args = [])
     */
    public function listKeys(
        string $kvs,
        int $maxResults = 10
    ): Generator {
        $params = [
            'KvsARN' => $kvs, // REQUIRED
            'MaxResults' => $maxResults,
        ];
        // ⚠️ 這裡刻意不 catch AWS 例外，讓它上拋。
        //
        // 原本有一個 `catch (DynamoDbException)`，但本方法呼叫的是 CloudFrontKeyValueStore
        // （拋 CloudFrontKeyValueStoreException），與 DynamoDbException 是平行類別——
        // 該 catch 即使補上 import 仍是死碼，只是額外帶了一個 `!kd($e)`（dump-and-die）地雷。
        //
        // 不改成 catch CloudFrontKeyValueStoreException 的理由：本方法宣告 `: Generator`，
        // generator 內的 `return $value` 不會傳回給 foreach 的呼叫端，只會讓迭代靜默結束。
        // 分頁中途失敗時呼叫端會拿到「不完整但看起來正常」的 key 集合，而呼叫端（Galao）
        // 據此做刪除決策——靜默截斷比讓例外上拋危險得多。故維持例外上拋，
        // 與本修正前的實際運行行為一致，由呼叫端決定如何處理。
        do {
            $result = $this->get_client('CloudFrontKeyValueStore')->listKeys($params);
            foreach ($result['Items'] as $item) {
                yield $item;
            }
            $params['NextToken'] = $result['NextToken'];
        } while (!empty($params['NextToken']));
    }

    /*
     * @method \Aws\Result updateKeys(array $args = [])
     */
    public function updateKeys(
        string $kvs,
        string $ifMatch,
        array $puts = [],
        array $deletes = []
    ): Aws\Result|bool|string {
        try {
            $params = [
                'KvsARN' => $kvs,
                'IfMatch' => $ifMatch,
            ];
            if (!empty($puts)) {
                $params['Puts'] = $puts;
            }
            if (!empty($deletes)) {
                $params['Deletes'] = $deletes;
            }

            $result = $this->get_client('CloudFrontKeyValueStore')->updateKeys($params);

            return $result;
        } catch (CloudFrontKeyValueStoreException $e) {
            return match (true) {
                $e->getStatusCode() == 404 => false,
                empty($this->_config['debug']) => false,
                default => $e->getMessage(),
            };
        }
    }

    /**
     * This client is used to interact with the **Amazon CloudFront KeyValueStore** service.
     * @method \Aws\Result deleteKey(array $args = [])
     */

    /**
     * @method Model createQueue(array $args = array()) {@command Sqs CreateQueue}
     */
    public function createQueue(string $queueName, $attributes = false)
    {
        try {
            $params = [
                'QueueName' => ENVIRONMENT.'_'.$queueName,
            ];
            if (is_array($attributes)) {
                $params['Attributes'] = $attributes;
            }
            $result = $this->get_client('Sqs')->createQueue($params);

            return $result->get('QueueUrl');
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model getQueueUrl(array $args = array()) {@command Sqs GetQueueUrl}
     */
    public function getQueueUrl(string $queueName, $queueOwnerAWSAccountId = false)
    {
        try {
            $params = [
                'QueueName' => ENVIRONMENT.'_'.$queueName,
            ];
            if (!empty($queueOwnerAWSAccountId)) {
                $params['QueueOwnerAWSAccountId'] = $queueOwnerAWSAccountId;
            }
            $result = $this->get_client('Sqs')->getQueueUrl($params);

            return $result->get('QueueUrl');
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model listQueues(array $args = array()) {@command Sqs ListQueues}
     */
    public function listQueues($queueNamePrefix = false)
    {
        $params = [];
        if (!empty($queueNamePrefix)) {
            $params['QueueNamePrefix'] = str_starts_with($queueNamePrefix, ENVIRONMENT) ? $queueNamePrefix : (ENVIRONMENT.'_'.$queueNamePrefix);
        }
        try {
            $result = $this->get_client('Sqs')->listQueues($params);

            return $result->get('QueueUrls');
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model sendMessage(array $args = array()) {@command Sqs SendMessage}
     */
    public function sendMessage(string $queueUrl, $messageBody, $delaySeconds = false)
    {
        try {
            $params = [
                'QueueUrl' => $queueUrl,
                'MessageBody' => $messageBody,
            ];
            if ($delaySeconds !== false) {
                $params['DelaySeconds'] = $delaySeconds;
            }
            $result = $this->get_client('Sqs')->sendMessage($params);
            if ($result->get('MD5OfMessageBody') == md5($messageBody)) {
                return $result->get('MessageId');
            } else {
                return empty($this->_config['debug']) ? 'MD5 of message not matched' : false;
            }
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model sendMessageBatch(array $args = array()) {@command Sqs SendMessageBatch}
     */
    public function sendMessageBatch(string $queueUrl, $entries)
    {
        try {
            $params = [
                'QueueUrl' => $queueUrl,
                'Entries' => $entries,
            ];

            return $this->get_client('Sqs')->sendMessageBatch($params);
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model receiveMessage(array $args = array()) {@command Sqs ReceiveMessage}
     */
    public function receiveMessage(string $queueUrl, $maxNumberOfMessages = null, $visibilityTimeout = null, $waitTimeSeconds = null, $attributeNames = null)
    {
        try {
            $params = [
                'QueueUrl' => $queueUrl,
                'Attributes' => [
                ],
            ];
            if ($maxNumberOfMessages !== null) {
                $params['MaxNumberOfMessages'] = $maxNumberOfMessages;
            }
            if ($visibilityTimeout !== null) {
                $params['VisibilityTimeout'] = $visibilityTimeout;
            }
            if ($waitTimeSeconds !== null) {
                $params['WaitTimeSeconds'] = $waitTimeSeconds;
            }
            if ($attributeNames !== null) {
                $params['AttributeNames'] = $attributeNames;
            }
            $result = $this->get_client('Sqs')->receiveMessage($params);

            return $result->get('Messages');
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model deleteMessage(array $args = array()) {@command Sqs DeleteMessage}
     */
    public function deleteMessage(string $queueUrl, $receiptHandle)
    {
        try {
            return $this->get_client('Sqs')->deleteMessage([
                'QueueUrl' => $queueUrl,
                'ReceiptHandle' => $receiptHandle,
            ]);
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model changeMessageVisibility(array $args = array()) {@command Sqs ChangeMessageVisibility}
     */
    public function changeMessageVisibility(string $queueUrl, $receiptHandle, $visibilityTimeout)
    {
        try {
            return $this->get_client('Sqs')->changeMessageVisibility([
                'QueueUrl' => $queueUrl,
                'ReceiptHandle' => $receiptHandle,
                'VisibilityTimeout' => $visibilityTimeout,
            ]);
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model changeMessageVisibilityBatch(array $args = array()) {@command Sqs ChangeMessageVisibilityBatch}
     */
    public function changeMessageVisibilityBatch(string $queueUrl, $entries)
    {
        try {
            return $this->get_client('Sqs')->changeMessageVisibilityBatch([
                'QueueUrl' => $queueUrl,
                'Entries' => $entries,
            ]);
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model deleteMessageBatch(array $args = array()) {@command Sqs DeleteMessageBatch}
     */
    public function deleteMessageBatch(string $queueUrl, $entries)
    {
        try {
            return $this->get_client('Sqs')->deleteMessageBatch([
                'QueueUrl' => $queueUrl,
                'Entries' => $entries,
            ]);
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function createTable(array $params = [], bool|int $retry = false): Aws\Result
    {
        $attempts = 0;
        $credentials_error = null;

        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $sleep) {
            ++$attempts;

            try {
                return $this->get_client('DynamoDb')->createTable($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                $credentials_error = $e;

                if (empty($sleep)
                    or match (gettype($retry)) {
                        'boolean' => !$retry,
                        default => $retry-- <= 0,
                    }
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                // 在邊界翻譯成本套件的型別並上拋，不再回 false。
                // 呼叫端要降級請明確 catch DynamoDbUnavailable／DynamoDbConditionFailed，
                // 不要把所有失敗都當成「查無資料」——那會讓 ValidationException
                // 這種我們自己的 bug 永遠沒有人發現。
                throw $this->_translate_dynamodb_error(__FUNCTION__, $e, $params);
            }
        }

        // 重試跑完仍拿不到憑證。1.39.12 之前這裡是 `return false`，與「查無資料」
        // 無法區分；改為上拋並把最後一次的 CredentialsException 掛在 previous。
        throw $this->_translate_credentials_error(
            __FUNCTION__,
            $credentials_error,
            $params,
            $attempts
        );
    }

    public function getItem(array $params = [], bool|int $retry = false): Aws\Result
    {
        $attempts = 0;
        $credentials_error = null;

        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $sleep) {
            ++$attempts;

            try {
                return $this->get_client('DynamoDb')->getItem($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                $credentials_error = $e;

                if (empty($sleep)
                    or match (gettype($retry)) {
                        'boolean' => !$retry,
                        default => $retry-- <= 0,
                    }
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                // 在邊界翻譯成本套件的型別並上拋，不再回 false。
                // 呼叫端要降級請明確 catch DynamoDbUnavailable／DynamoDbConditionFailed，
                // 不要把所有失敗都當成「查無資料」——那會讓 ValidationException
                // 這種我們自己的 bug 永遠沒有人發現。
                throw $this->_translate_dynamodb_error(__FUNCTION__, $e, $params);
            }
        }

        // 重試跑完仍拿不到憑證。1.39.12 之前這裡是 `return false`，與「查無資料」
        // 無法區分；改為上拋並把最後一次的 CredentialsException 掛在 previous。
        throw $this->_translate_credentials_error(
            __FUNCTION__,
            $credentials_error,
            $params,
            $attempts
        );
    }

    public function putItem(array $params = [], bool|int $retry = false): Aws\Result
    {
        $attempts = 0;
        $credentials_error = null;

        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $sleep) {
            ++$attempts;

            try {
                return $this->get_client('DynamoDb')->putItem($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                $credentials_error = $e;

                if (empty($sleep)
                    or match (gettype($retry)) {
                        'boolean' => !$retry,
                        default => $retry-- <= 0,
                    }
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                // 在邊界翻譯成本套件的型別並上拋，不再回 false。
                // 呼叫端要降級請明確 catch DynamoDbUnavailable／DynamoDbConditionFailed，
                // 不要把所有失敗都當成「查無資料」——那會讓 ValidationException
                // 這種我們自己的 bug 永遠沒有人發現。
                throw $this->_translate_dynamodb_error(__FUNCTION__, $e, $params);
            }
        }

        // 重試跑完仍拿不到憑證。1.39.12 之前這裡是 `return false`，與「查無資料」
        // 無法區分；改為上拋並把最後一次的 CredentialsException 掛在 previous。
        throw $this->_translate_credentials_error(
            __FUNCTION__,
            $credentials_error,
            $params,
            $attempts
        );
    }

    public function queryItem(array $params = [], bool|int $retry = false): Aws\Result
    {
        $attempts = 0;
        $credentials_error = null;

        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $sleep) {
            ++$attempts;

            try {
                return $this->get_client('DynamoDb')->query($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                $credentials_error = $e;

                if (empty($sleep)
                    or match (gettype($retry)) {
                        'boolean' => !$retry,
                        default => $retry-- <= 0,
                    }
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                // 在邊界翻譯成本套件的型別並上拋，不再回 false。
                // 呼叫端要降級請明確 catch DynamoDbUnavailable／DynamoDbConditionFailed，
                // 不要把所有失敗都當成「查無資料」——那會讓 ValidationException
                // 這種我們自己的 bug 永遠沒有人發現。
                throw $this->_translate_dynamodb_error(__FUNCTION__, $e, $params);
            }
        }

        // 重試跑完仍拿不到憑證。1.39.12 之前這裡是 `return false`，與「查無資料」
        // 無法區分；改為上拋並把最後一次的 CredentialsException 掛在 previous。
        throw $this->_translate_credentials_error(
            __FUNCTION__,
            $credentials_error,
            $params,
            $attempts
        );
    }

    public function updateItem(array $params = [], bool|int $retry = false): Aws\Result
    {
        $attempts = 0;
        $credentials_error = null;

        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $sleep) {
            ++$attempts;

            try {
                return $this->get_client('DynamoDb')->updateItem($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                $credentials_error = $e;

                if (empty($sleep)
                    or match (gettype($retry)) {
                        'boolean' => !$retry,
                        default => $retry-- <= 0,
                    }
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                // 在邊界翻譯成本套件的型別並上拋，不再回 false。
                // 呼叫端要降級請明確 catch DynamoDbUnavailable／DynamoDbConditionFailed，
                // 不要把所有失敗都當成「查無資料」——那會讓 ValidationException
                // 這種我們自己的 bug 永遠沒有人發現。
                throw $this->_translate_dynamodb_error(__FUNCTION__, $e, $params);
            }
        }

        // 重試跑完仍拿不到憑證。1.39.12 之前這裡是 `return false`，與「查無資料」
        // 無法區分；改為上拋並把最後一次的 CredentialsException 掛在 previous。
        throw $this->_translate_credentials_error(
            __FUNCTION__,
            $credentials_error,
            $params,
            $attempts
        );
    }

    public function deleteItem(array $params = [], bool|int $retry = false): Aws\Result
    {
        $attempts = 0;
        $credentials_error = null;

        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $sleep) {
            ++$attempts;

            try {
                return $this->get_client('DynamoDb')->deleteItem($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                $credentials_error = $e;

                if (empty($sleep)
                    or match (gettype($retry)) {
                        'boolean' => !$retry,
                        default => $retry-- <= 0,
                    }
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                // 在邊界翻譯成本套件的型別並上拋，不再回 false。
                // 呼叫端要降級請明確 catch DynamoDbUnavailable／DynamoDbConditionFailed，
                // 不要把所有失敗都當成「查無資料」——那會讓 ValidationException
                // 這種我們自己的 bug 永遠沒有人發現。
                throw $this->_translate_dynamodb_error(__FUNCTION__, $e, $params);
            }
        }

        // 重試跑完仍拿不到憑證。1.39.12 之前這裡是 `return false`，與「查無資料」
        // 無法區分；改為上拋並把最後一次的 CredentialsException 掛在 previous。
        throw $this->_translate_credentials_error(
            __FUNCTION__,
            $credentials_error,
            $params,
            $attempts
        );
    }

    /**
     * DynamoDB 的 Query／Scan 迭代器。
     *
     * ⚠️ 這裡必須**代為迭代**，不能只把 `$client->getIterator()` 包在 try 裡。
     * SDK 的 `AwsClientTrait::getIterator()` 在 paginator 同時具備 input_token 與
     * output_token 時（DynamoDB Query／Scan 兩者皆有，已實測）會走
     * `getPaginator($name, $args)->search($key)`，而 `ResultPaginator::search()`
     * 回傳的是 `flatmap()` —— 一個 **lazy generator**。
     * 也就是說 try 區塊內**完全沒有任何 AWS 請求發生**，真正的分頁請求是在呼叫端
     * foreach 的時候才打出去，那時控制流早已離開 try，catch 形同死碼，原始的
     * DynamoDbException 會未經翻譯、未經記錄、不帶 marker 就逸出。
     *
     * 本方法自己是 generator，所以對呼叫端而言仍是 lazy（不會把結果全部讀進記憶體），
     * 但 yield 迴圈落在 try 內，分頁中途的失敗就能被攔下來翻譯。
     *
     * ⚠️ 副作用：本方法變成 generator function 之後，**連 `get_client()` 都延後到
     * 第一次迭代才執行**。取得回傳值但從未迭代 ⇒ 完全不會發出請求、也不會有例外。
     * 呼叫端若只是「取得 iterator 但不迭代」，不要期待在那一行拿到錯誤。
     *
     * ⚠️ 刻意 `yield $item`（不帶 key）：SDK 的 `flatten()` 本來就是無 key 產出
     * （0..N），若改成 `yield $key => $item` 會把內層的 key 重新曝露出來，遇到
     * 重複 key 的 iterable 時 `iterator_to_array()` 會靜默吃掉資料（實測 4 筆
     * 產出只剩 2 筆）。不帶 key 則永遠是 0..N，不可能碰撞。
     *
     * @return \Generator SDK 原本也是回傳 Generator（flatmap），型別相容
     */
    public function getIterator(string $type, array $params = []): \Generator
    {
        try {
            foreach ($this->get_client('DynamoDb')->getIterator($type, $params) as $item) {
                yield $item;
            }
        } catch (DynamoDbException $e) {
            // 在邊界翻譯成本套件的型別並上拋，不再回 false。詳見 Aws_exceptions.php。
            throw $this->_translate_dynamodb_error(__FUNCTION__, $e, $params);
        } catch (\Aws\Exception\CredentialsException $e) {
            throw $this->_translate_credentials_error(__FUNCTION__, $e, $params);
        }
    }

    public function queryBatchItem(array $params = []): Aws\Result
    {
        try {
            return $this->get_client('DynamoDb')->batchGetItem($params);
        } catch (DynamoDbException $e) {
            // 在邊界翻譯成本套件的型別並上拋，不再回 false。詳見 Aws_exceptions.php。
            throw $this->_translate_dynamodb_error(__FUNCTION__, $e, $params);
        } catch (\Aws\Exception\CredentialsException $e) {
            throw $this->_translate_credentials_error(__FUNCTION__, $e, $params);
        }
    }

    public function putBatchItem(array $params = []): Aws\Result
    {
        try {
            return $this->get_client('DynamoDb')->BatchWriteItem($params);
        } catch (DynamoDbException $e) {
            // 在邊界翻譯成本套件的型別並上拋，不再回 false。詳見 Aws_exceptions.php。
            throw $this->_translate_dynamodb_error(__FUNCTION__, $e, $params);
        } catch (\Aws\Exception\CredentialsException $e) {
            throw $this->_translate_credentials_error(__FUNCTION__, $e, $params);
        }
    }

    public function queryScan(array $params = []): array
    {
        $result = [
            'items' => [],
            'count' => 0,
        ];
        try {
            do {
                $response = $this->get_client('DynamoDb')->scan($params);
                $items = $response->get('Items');
                $result['items'] = array_merge($result['items'], $items);
                $result['count'] += count($items);
                $params['ExclusiveStartKey'] = $response['LastEvaluatedKey'];
            } while (!empty($params['ExclusiveStartKey']));

            return $result;
        } catch (DynamoDbException $e) {
            // 在邊界翻譯成本套件的型別並上拋，不再回 false。詳見 Aws_exceptions.php。
            // ⚠️ 分頁到一半失敗時，已累積的 $result 會連同例外一起被丟棄。那是刻意的：
            // 呼叫端無法從「部分結果」分辨資料是否完整，靜默回傳半套比失敗更危險。
            throw $this->_translate_dynamodb_error(__FUNCTION__, $e, $params);
        } catch (\Aws\Exception\CredentialsException $e) {
            throw $this->_translate_credentials_error(__FUNCTION__, $e, $params);
        }
    }

    public function startQueryExecution(array $params = [])
    {
        try {
            $result = $this->get_client('Athena')->startQueryExecution([
                'QueryExecutionContext' => [
                    'Catalog' => $params['Catalog'] ?? 'AwsDataCatalog',
                    'Database' => $params['Database'],
                ],
                'QueryString' => $params['sql'], // REQUIRED
                'ResultConfiguration' => [
                    'EncryptionConfiguration' => [
                        'EncryptionOption' => 'SSE_S3' // REQUIRED
                    ],
                    'OutputLocation' => $params['OutputLocation'],
                ],
            ]);

            return $result;
        } catch (AthenaException $e) {
            return empty($this->_config['debug'])? false : $e->getMessage();
        }
    }

    public function getQueryExecution($QueryExecutionId)
    {
        $result = $this->get_client('Athena')->getQueryExecution($QueryExecutionId);
        return $result;
    }

    /**
     * @method \Aws\Result postToConnection(array $args = [])
    'ConnectionId' => '<string>', // REQUIRED
    'Data' => <string || resource || Psr\Http\Message\StreamInterface>, // REQUIRED
     */
    public function postToConnection(string $connectionId, $data)
    {
        try {
            $client = $this->get_client(
                'ApiGatewayManagementApi',
                [
                    'apiVersion' => '2018-11-29',
                    'endpoint' => $this->_CI->config->item('connection_endpoint', 'aws'),
                ]
            );
            return $client->postToConnection([
                'ConnectionId' => $connectionId,
                'Data' => $data,
            ]);
        } catch (Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /*
     * @class SqsClient
     *
     * @method Model addPermission(array $args = array()) {@command Sqs AddPermission}
     * @method Model changeMessageVisibilityBatch(array $args = array()) {@command Sqs ChangeMessageVisibilityBatch}
     * @method Model deleteQueue(array $args = array()) {@command Sqs DeleteQueue}
     * @method Model getQueueAttributes(array $args = array()) {@command Sqs GetQueueAttributes}
     * @method Model removePermission(array $args = array()) {@command Sqs RemovePermission}
     * @method Model setQueueAttributes(array $args = array()) {@command Sqs SetQueueAttributes}
     * @method ResourceIteratorInterface getListQueuesIterator(array $args = array()) The input array uses the parameters of the ListQueues operation
     */


    /**
     * The following methods is used to interact with the **AWS Batch** service.
     */

    public function describe_job_queues(array $jobQueues =[])
    {
        if (empty($jobQueues)) {
            return false;
        }
        try {
            $result = $this->get_client('Batch')->describeJobQueues([
                'jobQueues' => $jobQueues,
               ]);
        } catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    public function register_job_definition(array $job_definition)
    {
        try {
            $result =  $this->get_client('Batch')->registerJobDefinition($job_definition);
        } catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    public function deregister_job_definition($job_definition)
    {
        if (empty($job_definition)) {
            return false;
        }
        try {
            $result = $this->get_client('Batch')->deregisterJobDefinition([
                    'jobDefinition' => $job_definition
                ]);
        } catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    public function submit_job(array $job_definition)
    {
        try {
            $result = $this->get_client('Batch')->submitJob($job_definition);
        } catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    public function cancel_job(string $jobId, string $reason)
    {
        if (empty($jobId) || empty($reason)) {
            return false;
        }
        try {
            $result = $this->get_client('Batch')->cancelJob([
                'jobId' => $jobId,
                'reason' => $reason,
            ]);
        } catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
        ;
    }

    public function terminate_job(string $jobId, string $reason)
    {
        if (empty($jobId) || empty($reason)) {
            return false;
        }

        try {
            $result = $this->get_client('Batch')->terminateJob([
                'jobId' => $jobId,
                'reason' => $reason,
            ]);
        } catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    public function list_jobs($jobQueue, $jobStatus = 'RUNNING', $maxResults = 100, $nextToken = null)
    {
        $status = ['SUBMITTED', 'PENDING', 'RUNNABLE', 'STARTING','RUNNING', 'SUCCEEDED', 'FAILED'];

        if (empty($jobQueue) || !in_array($jobStatus, $status)) {
            return false;
        }
        try {
            $result = $this->get_client('Batch')->listJobs([
                'jobQueue' => $jobQueue, // REQUIRED
                'jobStatus' => $jobStatus,
                'maxResults' => $maxResults,
                'nextToken' => $nextToken,
            ]);
        } catch (Exception $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    //$ids : A space-separated list of up to 100 job IDs.
    public function describe_jobs(array $ids)
    {
        if (empty($ids)) {
            return false;
        }
        $result = [];
        $job_array = array_chunk($ids, 100);

        foreach ($job_array as $item) {
            try {
                $res = $this->get_client('Batch')->describeJobs([
                    'jobs' => $item, // REQUIRED
                ]);
            } catch (BatchException $e) {
                $result['error_msg'][] = $e->getMessage();
            }

            if (!empty($res)) {
                $result = array_merge($result, $res['jobs']);
            }
        }
        return $result;
    }

    /*
     * @method \Aws\Result publish(array $args = [])
     * Each SMS message can contain up to 140 bytes,
     * and the character limit depends on the encoding scheme.
     * For example, an SMS message can contain:
     * - 160 GSM characters
     * - 140 ASCII characters
     * - 70 UCS-2 characters
     */
    public function publish(array $params)
    {
        try {
            if (empty($params['Message'])) {
                throw new Exception('Missing parameter, "Message" is required.', 500);
            }
            if ($this->_valid_json($params['Message'])) {
                $params['MessageStructure'] = 'json';
            }
            if (!$this->_valid_arn($params)) {
                throw new Exception('Invalid parameter, only one of "PhoneNumber", "TargetArn", "TopicArn" should exist', 500);
            }
            if (isset($params['PhoneNumber']) &&
                !$this->_valid_E164($params['PhoneNumber'])
            ) {
                throw new Exception('PhoneNumber should use E.164 format.', 500);
            }
            if (isset($params['Subject']) &&
                !$this->_valid_subject($params['Subject'])
            ) {
                throw new Exception('Subject format is invalid.', 500);
            }
            $result = $this->get_client('Sns')->publish($params);
            return $result['MessageId'];
        } catch (Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function subscribe($endpoint, $protocol, $topic_arn)
    {
        try {
            $this->_valid_endpoint($endpoint, $protocol);
        } catch (Exception $e) {
            throw new Exception('Endpoint format is not ' . $e->getMessage(), $e->getCode());
        }
        try {
            $result = $this->get_client('Sns')->subscribe([
                'Endpoint' => $endpoint,
                'Protocol' => $protocol,
                'TopicArn' => $topic_arn,
            ]);
            return $result['SubscriptionArn'];
        } catch (Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function unsubscribe($subscription_arn)
    {
        try {
        } catch (Exception) {
        }
    }

    public function get_ip_ranges($service)
    {
        try {
            $endpoint = match ($service) {
                'CLOUDFRONT' => 'http://d7uri8nf7uskq.cloudfront.net/tools/list-cloudfront-ips',
                default => throw new Exception('Unsupport AWS service: ' . $service, 400),
            };
            $client = new GuzzleHttp\Client();
            $response = $client->request('GET', $endpoint);
            $status_code = $response->getStatusCode();
            if ($status_code !== 200) {
                throw new Exception('Failed to get ip ranges of AWS ' . $service, 400);
            }

            $response_content = $response->getBody()->getContents();
            $response_json = json_decode($response_content, true);
            return array_merge(
                $response_json['CLOUDFRONT_GLOBAL_IP_LIST'],
                $response_json['CLOUDFRONT_REGIONAL_EDGE_IP_LIST']
            );
        } catch (RequestException|Exception) {
        }
    }

    public function get_client($name, $options = null)
    {
        if (!isset($this->_client_pool[$name])) {
            if (in_array($name, ['CloudFrontKeyValueStore'])) {
                $options['signature'] = new SignatureV4(
                    service: strtolower($name),
                    region: $this->_config['region'],
                    options: ['use_v4a' => true]
                );
            }
            $this->_client_pool[$name] = $this->_sdk->{'create' . $name}($options);
        }
        return $this->_client_pool[$name];
    }

    public function sendRawEmail(string $rawMessage, string $source = null, $destinations = [])
    {
        try {
            $params = ['RawMessage' => ['Data' => $rawMessage]];
            if (!empty($source)) {
                $params['Source'] = $source;
            }
            if (!empty($destinations)) {
                $params['Destinations'] = is_array($destinations) ?
                    $destinations :
                    explode(',', str_replace(' ', '', $destinations));
            }

            return $this->get_client(
                'Ses',
                ['region' => 'us-west-2']
            )->sendRawEmail($params);
        } catch (SesException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    private function _valid_endpoint($endpoint, $protocol)
    {
        switch ($protocol) {
            case 'http':
            case 'https':
                if (!str_starts_with($endpoint, $protocol . '://')) {
                    throw new Exception($protocol, 500);
                }
                break;
            case 'email':
            case 'email-json':
                $this->_CI->load->helper('email');
                if (!valid_email($endpoint)) {
                    throw new Exception('email', 500);
                }
                break;
            case 'sms':
                if (!$this->_valid_E164($endpoint)) {
                    throw new Exception('E.164', 500);
                }
                break;
            case 'sqs':
            case 'lambda':
                if (!str_starts_with($endpoint, 'arn:aws:' . $protocol)) {
                    throw new Exception('ARN', 500);
                }
                break;
            case 'application':
                if (!str_starts_with($endpoint, 'arn:aws:sns')) {
                    throw new Exception('ARN', 500);
                }
                break;
            default:
                throw new Exception('valid protocol: ' . $protocol, 500);
                break;
        }
    }

    public function setDebug(bool $debug)
    {
        $this->_config['debug'] = $debug;
    }

    private function _return_bucket_policy($bucket_name = '')
    {
        return '{
            "Version": "2008-10-17",
            "Id": "PolicyForCloudFrontPrivateContent",
            "Statement": [
                {
                    "Sid": "1",
                    "Effect": "Allow",
                    "Principal": {
                        "AWS": "arn:aws:iam::cloudfront:user/CloudFront Origin Access Identity '.$this->_cfIdentity.'"
                    },
                    "Action": "s3:GetObject",
                    "Resource": "arn:aws:s3:::'.$bucket_name.'/*"
                }
            ]
        }';
    }

    private function _return_distribution_config_array($bucket_name = '', $domain_name = '', $enabled = true)
    {
        $origin_id = 'S3-'.$bucket_name;

        return [
            'DistributionConfig' => [
                'CallerReference' => md5(time()),
                'Aliases' => [
                    'Quantity' => 1,
                    'Items' => [$domain_name],
                ],
                'DefaultRootObject' => 'index.html',
                'Origins' => [
                    'Quantity' => 1,
                    'Items' => [
                        [
                            'Id' => $origin_id,
                            'DomainName' => strtolower($bucket_name.'.s3.amazonaws.com'),
                            'S3OriginConfig' => [
                                'OriginAccessIdentity' => 'origin-access-identity/cloudfront/'.$this->_cfIdentity,
                            ],
                        ],
                    ],
                ],
                'DefaultCacheBehavior' => [
                    'TargetOriginId' => $origin_id,
                    'ForwardedValues' => [
                        'QueryString' => false,
                    ],
                    'TrustedSigners' => [
                        'Enabled' => false,
                        'Quantity' => 0,
                        'Items' => [],
                ],
                    'ViewerProtocolPolicy' => ViewerProtocolPolicy::ALLOW_ALL,
                    'MinTTL' => 0,
                ],
                'CacheBehaviors' => ['Quantity' => 0, 'Items' => []],
                'Comment' => 'Distribution for '.$bucket_name,
                'Logging' => [
                    'Enabled' => false,
                    'Bucket' => '',
                    'Prefix' => '',
                ],
                'Enabled' => $enabled,
            ],
        ];
    }

    private function _valid_json($json)
    {
        if (is_string($json)) {
            $json = @json_decode($json, true);
            return json_last_error() === JSON_ERROR_NONE &&
                isset($json['default']);
        }
    }

    private function _valid_E164($phone)
    {
        return preg_match('/^\+?[1-9]\d{1,14}$/', $phone);
    }

    private function _valid_subject($subject)
    {
        return true;
        return preg_match('/^[\w:punct:][^\v]{0,99}$/', $subject);
    }

    private function _valid_arn(array $params)
    {
        $count = 0;
        if (isset($params['PhoneNumber'])) {
            ++$count;
        }
        if (isset($params['TargetArn'])) {
            ++$count;
        }
        if (isset($params['TopicArn'])) {
            ++$count;
        }
        return $count === 1;
    }

    /**
     * 記錄 AWS 操作失敗。
     *
     * 與服務無關：型別刻意收 `\Aws\Exception\AwsException`（所有服務的例外共同父類，
     * 實測 DynamoDb/S3/Ses/Sqs/CloudFront/CloudFrontKeyValueStore/Batch 皆繼承之，
     * 且都有 getAwsErrorCode()／isConnectionError()），而非 DynamoDbException——本檔
     * 另有約 37 處其他服務的 catch 同樣完全沒有儀表，將來要一併補 log 時不必再改簽章。
     * （1.40.0 仍只處理 DynamoDB 那 10 處，其餘服務的行為未變動。）
     *
     * 分類邏輯已抽到 AwsFailureCategory（Aws_exceptions.php），讓 log 標籤與
     * _translate_dynamodb_error() 翻譯出來的例外型別共用同一份判斷，避免出現
     * 「log 寫暫時性失敗、例外卻是 DynamoDbRequestRejected」這種不一致。
     *
     * ⚠️ 分類放在**訊息標籤**、而非只靠 log level，原因是 CodeIgniter 的 Log 只認
     * ERROR/DEBUG/INFO/ALL（`system/core/Log.php` 的 `$_levels`），**沒有 WARNING**；
     * 且 Galao 各 app 的 `log_threshold = 1`（只寫 ERROR），info/debug/warning 一律
     * 不落地。若把可重試錯誤記成 warning，等於完全不記。詳見 AwsFailureCategory。
     *
     * @param string      $operation 呼叫來源方法名（__FUNCTION__）
     * @param AwsException $e
     * @param string|null $table    DynamoDB 表名（若可得），補進訊息便於排查
     */
    private function _log_aws_error(
        string $operation,
        AwsException $e,
        ?string $table = null,
        ?AwsFailureCategory $category = null,
    ): void {
        $code = (string) $e->getAwsErrorCode();
        // 呼叫端已經算過分類時傳進來，避免對同一個例外重複算
        $category ??= AwsFailureCategory::of($e);

        $this->_write_aws_log(
            $category->logLevel(),
            sprintf(
                'Aws_lib::%s AWS %s%s: %s',
                $operation,
                $category->label(),
                AwsMessageFormat::tableSuffix($table),
                $code !== '' ? $code . ' - ' . $e->getMessage() : $e->getMessage()
            )
        );
    }

    /**
     * 實際寫 log。
     *
     * 本套件不保證跑在 CodeIgniter 內（CLI 工具、測試 bootstrap、其他框架）——
     * 而那正是「套件被獨立使用」的情境。若在此靜默返回，失敗會完全不留記錄，
     * 與本次修正的目的（讓失敗可觀測）自相矛盾。故退回 error_log()。
     */
    private function _write_aws_log(string $level, string $message): void
    {
        if (function_exists('log_message')) {
            log_message($level, $message);

            return;
        }

        error_log(strtoupper($level) . ' - ' . $message);
    }

    /**
     * 把 DynamoDB 的 SDK 例外翻譯成本套件定義的型別（exception translation）。
     *
     * 這裡是 anti-corruption layer 的邊界：對外只吐 Ecrowdmedia\Aws\Exception
     * 下的少數幾種型別，原始的 DynamoDbException 保留在 getPrevious()，
     * 呼叫端因此仍查得到 AWS error code、request id 與原訊息。
     *
     * 回傳（而非直接 throw）是為了讓呼叫點寫成 `throw $this->_translate_...()`，
     * 靜態分析才看得出該分支一定中斷流程。
     *
     * @param array $params 原始 DynamoDB 參數，僅用來取出表名補進訊息——
     *                      翻譯後的例外只帶 operation 名稱的話，線上排查時
     *                      無法知道是哪張表出問題。不記其他欄位（可能含 PII）。
     * @return AwsOperationException 由呼叫端 throw
     */
    private function _translate_dynamodb_error(
        string $operation,
        DynamoDbException $e,
        array $params = [],
    ): AwsOperationException {
        $table = $this->_dynamodb_table_name($params);

        // 分類算一次，log 與例外共用——兩邊各算一次除了浪費，也留下「哪天其中
        // 一邊被改掉」的縫。
        $category = AwsFailureCategory::of($e);

        $this->_log_aws_error($operation, $e, $table, $category);

        return match ($category) {
            AwsFailureCategory::Expected => DynamoDbConditionFailed::during($operation, $e, $table, $category),
            AwsFailureCategory::Transient => DynamoDbUnavailable::during($operation, $e, $table, $category),
            AwsFailureCategory::Defect => DynamoDbRequestRejected::during($operation, $e, $table, $category),
        };
    }

    /**
     * 把 CredentialsException 翻譯成本套件的型別，並確保留下 log。
     *
     * 兩條路都走這裡：沒有重試迴圈的四個方法（getIterator／queryBatchItem／
     * putBatchItem／queryScan）用 $attempts = 1；六個帶重試迴圈的方法在迴圈
     * 跑完後帶入實際嘗試次數。
     *
     * 統一走同一個入口是刻意的——1.40.0 之前憑證耗盡是唯一「拋了例外卻沒有任何
     * library log」的路徑，若讓兩邊各自處理，很容易又漏掉一邊。
     *
     * @return AwsOperationException 由呼叫端 throw
     */
    private function _translate_credentials_error(
        string $operation,
        ?\Aws\Exception\CredentialsException $e,
        array $params = [],
        int $attempts = 1,
    ): AwsOperationException {
        $table = $this->_dynamodb_table_name($params);

        // 憑證問題屬 exogenous、可重試，標籤取自 AwsFailureCategory 而非寫死字串——
        // 寫死的話，哪天改標籤就只會漏掉憑證這條路徑。
        $this->_write_aws_log(
            'error',
            sprintf(
                'Aws_lib::%s AWS %s%s: CredentialsException（已嘗試 %d 次）- %s',
                $operation,
                AwsFailureCategory::Transient->label(),
                AwsMessageFormat::tableSuffix($table),
                $attempts,
                $e?->getMessage() ?? 'n/a'
            )
        );

        return AwsCredentialsUnavailable::afterRetries($operation, $attempts, $e, $table);
    }

    /**
     * 從 DynamoDB 參數取出表名。單筆操作是 TableName；批次操作（batchGetItem／
     * BatchWriteItem）的表名是 RequestItems 的 key，可能多張。
     *
     * 表名一律來自設定檔或常數，不是使用者輸入，但仍做兩件防護：
     *   1. 去掉控制字元——換行會讓一行 log 被偽造成兩行
     *   2. 截斷過長字串——批次操作若帶數十張表，會讓每一行 log 與每個例外訊息
     *      都拖上幾百個字元
     */
    private function _dynamodb_table_name(array $params): ?string
    {
        // 用 isset 而非 !empty：表名 '0' 是合法的，!empty('0') 為 false 會被誤判成沒有表名。
        if (isset($params['TableName']) && is_string($params['TableName'])) {
            $name = $params['TableName'];
        } elseif (isset($params['RequestItems']) && is_array($params['RequestItems'])) {
            $name = implode(',', array_keys($params['RequestItems']));
        } else {
            return null;
        }

        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';

        if ($name === '') {
            return null;
        }

        return mb_strlen($name) > 120 ? mb_substr($name, 0, 117) . '...' : $name;
    }
}
// END Aws_lib Class

/* End of file Aws_lib.php */

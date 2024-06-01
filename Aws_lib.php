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
use Aws\S3\Exception\S3Exception;
use Aws\Ses\Exception\SesException;
use Aws\Sqs\Exception\SqsException;
use Aws\Batch\Exception\BatchException;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

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
            $params['QueueNamePrefix'] = strpos($queueNamePrefix, ENVIRONMENT) === 0 ? $queueNamePrefix : (ENVIRONMENT.'_'.$queueNamePrefix);
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
                return $this->debug ? 'MD5 of message not matched' : false;
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

    public function createTable(array $params = [], bool|int $retry = false): bool|Aws\Result
    {
        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $index => $sleep) {
            try {
                return $this->get_client('DynamoDb')->createTable($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                if (empty($sleep)
                    or $retry-- <= 0
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                return empty($this->_config['debug']) ? false : $e->getMessage();
            }
        }
        return false;
    }

    public function getItem(array $params = [], bool|int $retry = false): bool|Aws\Result
    {
        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $index => $sleep) {
            try {
                return $this->get_client('DynamoDb')->getItem($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                if (empty($sleep)
                    or $retry-- <= 0
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                return empty($this->_config['debug']) ? false : $e->getMessage();
            }
        }
        return false;
    }

    public function putItem(array $params = [], bool|int $retry = false): bool|Aws\Result
    {
        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $index => $sleep) {
            try {
                return $this->get_client('DynamoDb')->putItem($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                if (empty($sleep)
                    or $retry-- <= 0
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                return empty($this->_config['debug']) ? false : $e->getMessage();
            }
        }
        return false;
    }

    public function queryItem(array $params = [], bool|int $retry = false): bool|Aws\Result
    {
        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $index => $sleep) {
            try {
                return $this->get_client('DynamoDb')->query($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                if (empty($sleep)
                    or $retry-- <= 0
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                return empty($this->_config['debug']) ? false : $e->getMessage();
            }
        }
        return false;
    }

    public function updateItem(array $params = [], bool|int $retry = false): bool|Aws\Result
    {
        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $index => $sleep) {
            try {
                return $this->get_client('DynamoDb')->updateItem($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                if (empty($sleep)
                    or $retry-- <= 0
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                return empty($this->_config['debug']) ? false : $e->getMessage();
            }
        }
        return false;
    }

    public function deleteItem(array $params = [], bool|int $retry = false): bool|Aws\Result
    {
        // 使用 Fibonacci sequence 當作延遲秒數，最長重試 6 次，總等待時間為 20 秒
        foreach ([1, 1, 2, 3, 5, 8, 0] as $index => $sleep) {
            try {
                return $this->get_client('DynamoDb')->deleteItem($params);
            } catch (\Aws\Exception\CredentialsException $e) {
                if (empty($sleep)
                    or $retry-- <= 0
                ) {
                    break;
                }
                sleep($sleep);
            } catch (DynamoDbException $e) {
                return empty($this->_config['debug']) ? false : $e->getMessage();
            }
        }
        return false;
    }

    public function getIterator(string $type, array $params = [])
    {
        try {
            return $this->get_client('DynamoDb')->getIterator($type, $params);
        } catch (DynamoDbException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function queryBatchItem(array $params = [])
    {
        try {
            return $this->get_client('DynamoDb')->batchGetItem($params);
        } catch (DynamoDbException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function putBatchItem(array $params = [])
    {
        try {
            return $this->get_client('DynamoDb')->BatchWriteItem($params);
        } catch (DynamoDbException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function queryScan(array $params = [])
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
            return empty($this->_config['debug']) ? false : $e->getMessage();
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

        } catch (Exception $e) {

        }
    }

    public function get_ip_ranges($service)
    {
        try {
            switch ($service) {
                case 'CLOUDFRONT':
                    $endpoint = 'http://d7uri8nf7uskq.cloudfront.net/tools/list-cloudfront-ips';
                    break;
                default:
                    throw new Exception('Unsupport AWS service: ' . $service, 400);
                    break;
            }
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
        } catch (RequestException $e) {
        } catch (Exception $e) {
        }
    }

    public function get_client($name, $options = null)
    {
        if (!isset($this->_client_pool[$name])) {
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

    public function listTopics($next_token = null)
    {
        try {
            $params = empty($next_token) ? [] : ['NextToken' => $next_token];
            return $this->_get_client('Sns')->listTopics($params);
        } catch (Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function listSubscriptionsByTopic($topic_arn, $next_token = null)
    {
        try {
            $params = [
                'NextToken' => $next_token,
                'TopicArn' => $topic_arn,
            ];
            return $this->_get_client('Sns')->listSubscriptionsByTopic($params);
        } catch (Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
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

    private function _valid_endpoint($endpoint, $protocol)
    {
        switch ($protocol) {
            case 'http':
            case 'https':
                if (strpos($endpoint, $protocol . '://') !== 0) {
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
                if (strpos($endpoint, 'arn:aws:' . $protocol) !== 0) {
                    throw new Exception('ARN', 500);
                }
                break;
            case 'application':
                if (strpos($endpoint, 'arn:aws:sns') !== 0) {
                    throw new Exception('ARN', 500);
                }
                break;
            default:
                throw new Exception('valid protocol: ' . $protocol, 500);
                break;
        }
    }
}
// END Aws_lib Class

/* End of file Aws_lib.php */

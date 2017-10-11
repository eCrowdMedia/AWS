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
use Aws\S3\Exception\S3Exception;
use Aws\CloudFront\Enum\ViewerProtocolPolicy;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\Sqs\Exception\SqsException;
use Aws\DynamoDb\DynamoDbClient;
use Aws\Batch\Exception\BatchException;
use Aws\Batch\BatchClient;

class Aws_lib
{
    private $_CI = null;
    private $_sdk = null;
    private $_s3Client = null;
    private $_cfClient = null;
    private $_sqsClient = null;
    private $_cfIdentity = null;
    private $_dynamoDbClient = null;
    private $_config = null;
    private $_batchClient = null;
    private $_client_pool = [];

    public function __construct($config = [])
    {
        if (empty($config)) {
            $this->_CI = &get_instance();
            $aws_config = $this->_CI->config->item('aws');
            if (empty($aws_config)) {
                $this->_CI->config->load('aws', true);
            }
            $this->_config = $this->_CI->config->item('aws_config', 'aws');
        }
        $this->_sdk = new Aws\Sdk($this->_config);
    }

    private function _get_client($name)
    {
        if (!isset($this->_client_pool[$name])) {
            $this->_client_pool[$name] = $this->_sdk->{'create' . $name}();
        }
        return $this->_client_pool[$name];
    }

    public function isBucketDnsCompatible($bucket_name)
    {
        try {
            return $this->_get_client('S3')->isBucketDnsCompatible($bucket_name) ? true : false;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function doesBucketExist($bucket_name)
    {
        try {
            return $this->_get_client('S3')->doesBucketExist($bucket_name) ? true : false;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model createBucket(array $args = array()) {@command S3 CreateBucket}
     */
    public function createBucket($bucket_name)
    {
        if (!$this->isBucketDnsCompatible($bucket_name)) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
        if ($this->doesBucketExist($bucket_name)) {
            return false;
        }
        try {
            $this->_get_client('S3')->createBucket(
            [
                'Bucket' => $bucket_name,
                'ACL' => 'public-read',
                //add more items if required here
            ]);

            return true;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model headBucket(array $args = array()) {@command S3 HeadBucket}
     */
    public function headBucket($bucket_name)
    {
        try {
            return $this->_get_client('S3')->headBucket([
                'Bucket' => $bucket_name,
            ]);
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model headObject(array $args = array()) {@command S3 HeadObject}
     */
    public function headObject($bucket_name, $key, array $args = [])
    {
        try {
            $args['Bucket'] = $bucket_name;
            $args['Key'] = $key;

            return $this->_get_client('S3')->headObject($args);
        } catch (S3Exception $e) {
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

    /**
     * @method Model putBucketPolicy(array $args = array()) {@command S3 PutBucketPolicy}
     */
    public function putBucketPolicy($bucket_name)
    {
        if ($this->doesBucketExist($bucket_name)) {
            try {
                $this->_get_client('S3')->putBucketPolicy(
                [
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
    public function deleteBucket($bucket_name)
    {
        try {
            $this->_get_client('S3')->deleteBucket([
                'Bucket' => $bucket_name,
            ]);

            return true;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function doesObjectExist($bucket_name, $key)
    {
        try {
            return $this->_get_client('S3')->doesObjectExist($bucket_name, $key) ? true : false;
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model putObject(array $args = array()) {@command S3 PutObject}
     */
    public function putObject($bucket_name, $key, $source, array $options = [])
    {
        try {
            if (empty($options['ACL'])) {
                $options['ACL'] = strpos($bucket_name, 'readmoo-cf-') === 0 ? 'public-read' : 'private';
            }
            if (empty($options['StorageClass'])) {
                $options['StorageClass'] = strpos($bucket_name, 'readmoo-cf-') === 0 ? 'REDUCED_REDUNDANCY' : 'STANDARD';
            }
            $options = [
                'Bucket' => $bucket_name,
                'Key' => $key,
            ] + $options;
            if (empty($options['SourceFile']) && empty($options['Body'])) {
                $options[is_file($source) ? 'SourceFile' : 'Body'] = $source;
            }
            if (isset($options['SourceFile']) && empty($options['ContentType'])) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $options['ContentType'] = finfo_file($finfo, $options['SourceFile']);
            }

            return $this->_get_client('S3')->putObject($options);
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model copyObject(array $args = array()) {@command S3 CopyObject}
     */
    public function copyObject($bucket_name, $key, $source, array $options = [])
    {
        try {
            if (empty($options['ACL'])) {
                $options['ACL'] = strpos($bucket_name, 'readmoo-cf-') === 0 ? 'public-read' : 'private';
            }
            if (empty($options['StorageClass'])) {
                $options['StorageClass'] = strpos($bucket_name, 'readmoo-cf-') === 0 ? 'REDUCED_REDUNDANCY' : 'STANDARD';
            }
            $options = [
                'Bucket' => $bucket_name,
                'Key' => $key,
                'CopySource' => implode('/', array_map('rawurlencode', explode('/', $source))),
            ] + $options;

            return $this->_get_client('S3')->copyObject($options);
        } catch (S3Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model deleteObject(array $args = array()) {@command S3 DeleteObject}
     */
    public function deleteObject($bucket_name, $s3key)
    {
        try {
            $this->_get_client('S3')->deleteObject([
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
    public function deleteObjects($bucket_name, $objects)
    {
        try {
            $this->_get_client('S3')->deleteObjects([
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
    public function deleteMatchingObjects($bucket_name, $prefix = '', $regex = '', array $options = [])
    {
        try {
            return $this->_get_client('S3')->deleteMatchingObjects($bucket_name, $prefix, $regex, $options);
        } catch (RuntimeException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function registerStreamWrapper()
    {
        return $this->_get_client('S3')->registerStreamWrapper();
    }

    /**
     * @method Model listObjects(array $args = array()) {@command S3 ListObjects}
     */
    public function listObjects($bucket_name, $prefix = '', $max_keys = 1000)
    {
        try {
            return $this->_get_client('S3')->listObjects([
                'Bucket' => $bucket_name,
                'Prefix' => $prefix,
                'MaxKeys' => $max_keys,
            ]);
        } catch (RuntimeException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * Create a pre-signed URL for a request
     *
     * @param string $method get, post, head, put, delete
     * @param int|string|\DateTime $expires The time at which the URL should expire.
     *                                      This can be a Unix timestamp,
     *                                      a PHP DateTime object,
     *                                      or a string that can be evaluated by strtotime
     * @return string
     */
    public function createPresignedUrl($method, $bucket, $key, $expires)
    {
        try {
            $s3_client = $this->_get_client('S3');
            $command = $s3_client->getCommand(
                ucfirst(strtolower($method)). 'Object',
                ['Bucket' => $bucket, 'Key' => $key]
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
    public function createDistribution($bucket_name, $domain_name)
    {
        try {
            $return = $this->_get_client('CF')->createDistribution($this->_return_distribution_config_array($bucket_name, $domain_name, true));

            return $return->toArray();
        } catch (CloudFrontException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
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

    public function disableDistribution($cfID)
    {
        try {
            $cf_client = $this->_get_client('CF');
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

    public function deleteDistribution($cfID)
    {
        try {
            $cf_client = $this->_get_client('CF');
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
    public function getDistribution($cfID)
    {
        try {
            return $this->_get_client('CF')->getDistribution(['Id' => $cfID]);
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
            $distributions = $this->_get_client('CF')->listDistributions();
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
    public function createInvalidation($cfID, array $paths, $caller_reference = false)
    {
        try {
            if (empty($paths)) {
                return false;
            } elseif (empty($caller_reference)) {
                $caller_reference = rtrim(base64_encode(sha1(implode("\x01", $paths).date('Y-m-d H:i:s'))), '=');

                return $this->_get_client('CF')->createInvalidation([
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
    public function createQueue($queueName, $attributes = false)
    {
        try {
            $params = [
                'QueueName' => ENVIRONMENT.'_'.$queueName,
            ];
            if (is_array($attributes)) {
                $params['Attributes'] = $attributes;
            }
            $result = $this->_get_client('Sqs')->createQueue($params);

            return $result->get('QueueUrl');
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model getQueueUrl(array $args = array()) {@command Sqs GetQueueUrl}
     */
    public function getQueueUrl($queueName, $queueOwnerAWSAccountId = false)
    {
        try {
            $params = [
                'QueueName' => ENVIRONMENT.'_'.$queueName,
            ];
            if (!empty($queueOwnerAWSAccountId)) {
                $params['QueueOwnerAWSAccountId'] = $queueOwnerAWSAccountId;
            }
            $result = $this->_get_client('Sqs')->getQueueUrl($params);

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
        try {
            $result = $this->_get_client('Sqs')->listQueues(
                empty($queueNamePrefix) ?
                    [] :
                    ['QueueNamePrefix' => ENVIRONMENT.'_'.$queueNamePrefix]
            );

            return $result->get('QueueUrls');
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model sendMessage(array $args = array()) {@command Sqs SendMessage}
     */
    public function sendMessage($queueUrl, $messageBody, $delaySeconds = false)
    {
        try {
            $params = [
                'QueueUrl' => $queueUrl,
                'MessageBody' => $messageBody,
            ];
            if ($delaySeconds !== false) {
                $params[QueueAttribute::DELAY_SECONDS] = $delaySeconds;
            }
            $result = $this->_get_client('Sqs')->sendMessage($params);
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
    public function sendMessageBatch($queueUrl, $entries)
    {
        try {
            $params = [
                'QueueUrl' => $queueUrl,
                'Entries' => $entries,
            ];

            return $this->_get_client('Sqs')->sendMessageBatch($params);
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model receiveMessage(array $args = array()) {@command Sqs ReceiveMessage}
     */
    public function receiveMessage($queueUrl, $maxNumberOfMessages = null, $visibilityTimeout = null, $waitTimeSeconds = null, $attributeNames = null)
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
            $result = $this->_get_client('Sqs')->receiveMessage($params);

            return $result->get('Messages');
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    /**
     * @method Model deleteMessage(array $args = array()) {@command Sqs DeleteMessage}
     */
    public function deleteMessage($queueUrl, $receiptHandle)
    {
        try {
            return $this->_get_client('Sqs')->deleteMessage([
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
    public function changeMessageVisibility($queueUrl, $receiptHandle, $visibilityTimeout)
    {
        try {
            return $this->_get_client('Sqs')->changeMessageVisibility([
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
    public function changeMessageVisibilityBatch($queueUrl, $entries)
    {
        try {
            return $this->_get_client('Sqs')->changeMessageVisibilityBatch([
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
    public function deleteMessageBatch($queueUrl, $entries)
    {
        try {
            return $this->_get_client('Sqs')->deleteMessageBatch([
                'QueueUrl' => $queueUrl,
                'Entries' => $entries,
            ]);
        } catch (SqsException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function putItem(array $params = [])
    {
        try {
            return $this->_get_client('DynamoDb')->putItem($params);
        } catch (DynamoDbException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function queryItem(array $params = [])
    {
        try {
            return $this->_get_client('DynamoDb')->query($params);
        } catch (DynamoDbException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function getIterator($type, array $params = [])
    {
        try {
            return $this->_get_client('DynamoDb')->getIterator($type, $params);
        } catch (DynamoDbException $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
    }

    public function queryBatchItem(array $params = [])
    {
        try {
            return $this->_get_client('DynamoDb')->batchGetItem($params);
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
                $response = $this->_get_client('DynamoDb')->scan($params);
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

    public function describe_job_queues(array $jobQueues =[]){
        if(empty($jobQueues)){
            return false;
        }
        try{
            $result = $this->_get_client('Batch')->describeJobQueues([
                'jobQueues' => $jobQueues,
               ]);
        } catch (BatchException $e) {
             $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    public function register_job_definition(array $job_definition){
        try {
            $result =  $this->_get_client('Batch')->registerJobDefinition($job_definition);
        }catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    public function deregister_job_definition($job_definition){
        if(empty($job_definition)){
            return false;
        }
        try {
            $result = $this->_get_client('Batch')->deregisterJobDefinition([
                    'jobDefinition' => $job_definition
                ]);
        }catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    public function submit_job(array $job_definition){
        try {
            $result = $this->_get_client('Batch')->submitJob($job_definition);
        }catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    public function cancel_job($jobId, $reason){
        if (empty($jobId) || empty($reason)) {
            return false;
        }
        try {
            $result = $this->_get_client('Batch')->cancelJob([
                'jobId' => $jobId,
                'reason' => $reason,
            ]);
        } catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;;
    }

    public function terminate_job($jobId, $reason){
        if(empty($jobId) || empty($reason)){
            return false;
        }

        try {
            $result = $this->_get_client('Batch')->terminateJob([
                'jobId' => $jobId,
                'reason' => $reason,
            ]);
        } catch (BatchException $e) {
            $result['error_msg'] = $e->getMessage();
        }
        return $result;
    }

    public function list_jobs($jobQueue, $jobStatus = 'RUNNING', $maxResults = 100, $nextToken = null){
            $status = ['SUBMITTED', 'PENDING', 'RUNNABLE', 'STARTING','RUNNING', 'SUCCEEDED', 'FAILED'];

            if(empty($jobQueue) || !in_array($jobStatus, $status)){
                return false;
            }
            try {
                $result = $this->_get_client('Batch')->listJobs([
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
                $res = $this->_get_client('Batch')->describeJobs([
                    'jobs' => $item, // REQUIRED
                ]);
            } catch (BatchException $e) {
                $result['error_msg'][] = $e->getMessage();
            }

            if(!empty($res)){
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
            if (isset($params['PhoneNumber'])) {
                if (!$this->_valid_E164($params['PhoneNumber'])) {
                    throw new Exception('PhoneNumber should use E.164 format.', 500);
                }
            }
            if (isset($params['Subject']) &&
                !$this->_valid_subject($params['Subject'])
            ) {
                throw new Exception('Subject format is invalid.', 500);
            }
            $result = $this->_get_client('Sns')->publish($params);
            return $result['MessageId'];
        } catch (Exception $e) {
            return empty($this->_config['debug']) ? false : $e->getMessage();
        }
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

    public function subscribe($endpoint, $protocol, $topic_arn)
    {
        try {
            $this->_valid_endpoint($endpoint, $protocol);
        } catch (Exception $e) {
            throw new Exception('Endpoint format is not ' . $e->getMessage(), $e->getCode());
        }
        try {
            $result = $this->_get_client('Sns')->subscribe([
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
                throw new Exception('unknown', 500);
                break;
        }
    }
}
// END Aws_lib Class

/* End of file Aws_lib.php */

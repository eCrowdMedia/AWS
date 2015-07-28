<?php
/**
 * Aws_lib Class
 *
 * @package     Readmoo
 * @subpackage  AWS
 * @category    Libraries
 * @author      Willy
 * @link        https://readmoo.com
 */
use Aws\Common\Aws;
use Aws\Common\Enum\Region;
use Aws\S3\Enum\CannedAcl;
use Aws\S3\Enum\StorageClass;
use Aws\S3\Exception\S3Exception;
use Aws\CloudFront\Enum\OriginProtocolPolicy;
use Aws\CloudFront\Enum\ViewerProtocolPolicy;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\Sqs\Enum\QueueAttribute;
use Aws\Sqs\Exception\SqsException;
use Aws\DynamoDb\DynamoDbClient;

class Aws_lib {
	private $aws;
	private $s3Client;
	private $cfClient;
	private $sqsClient;
	private $cfIdentity;
	private $dynamoDbClient;
	public $debug = false;

	function __construct($config = array())
	{
		if (empty($config)) {
			$CI =& get_instance();
			$aws_config = $CI->config->item('aws');
			if (empty($aws_config)) {
				$CI->config->load('aws', true);
			}
			$config = $CI->config->item('aws_config', 'aws');
		}
		$this->aws = Aws::factory($config);
		$this->s3Client = $this->aws->get('S3');
		$this->cfClient = $this->aws->get('CloudFront');
		$this->sqsClient = $this->aws->get('Sqs');
		$this->dynamoDbClient = $this->aws->get('DynamoDb');
	}

	public function isValidBucketName($bucket_name)
	{
		try {
			return $this->s3Client->isValidBucketName($bucket_name) ? true : false;
		}
		catch (S3Exception $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	public function doesBucketExist($bucket_name)
	{
		try {
			return $this->s3Client->doesBucketExist($bucket_name) ? true : false;
		}
		catch (S3Exception $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model createBucket(array $args = array()) {@command S3 CreateBucket}
	 */
	public function createBucket($bucket_name)
	{
		if ($this->isValidBucketName($bucket_name)) {
			if ($this->doesBucketExist($bucket_name)) {
				return false;
			}
			else {
				try {
					$this->s3Client->createBucket(
					array(
						'Bucket'=> $bucket_name,
						'ACL'  => CannedAcl::PUBLIC_READ
						//add more items if required here
					));
					return true;
				}
				catch (S3Exception $e) {
					return $this->debug ? $e->getMessage() : false;
				}
			}
		}
		else {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model headBucket(array $args = array()) {@command S3 HeadBucket}
 	 */
	public function headBucket($bucket_name)
	{
		try {
			return $this->s3Client->headBucket(array(
				'Bucket' => $bucket_name
			));
		}
		catch (S3Exception $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model headObject(array $args = array()) {@command S3 HeadObject}
 	 */
	public function headObject($bucket_name, $key, $args = array())
	{
		try {
			$args['Bucket'] = $bucket_name;
			$args['Key'] = $key;
			return $this->s3Client->headObject($args);
		}
		catch (S3Exception $e) {
			return $this->debug ? $e->getMessage() : false;
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
						"AWS": "arn:aws:iam::cloudfront:user/CloudFront Origin Access Identity '.$this->cfIdentity.'"
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
				$this->s3Client->putBucketPolicy(
				array(
					'Bucket'=> $bucket_name,
					'Policy'=> $this->_return_bucket_policy($bucket_name)
				));
				return true;
			}
			catch (S3Exception $e) {
				return $this->debug ? $e->getMessage() : false;
			}
		}
		else {
			return false;
		}
	}

	/**
	 * @method Model deleteBucket(array $args = array()) {@command S3 DeleteBucket}
	 */
	public function deleteBucket($bucket_name)
	{
		try {
			$this->s3Client->clearBucket($bucket_name);
			$this->s3Client->deleteBucket(array(
				'Bucket' => $bucket_name
			));
			return true;
		}
		catch (S3Exception $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	public function doesObjectExist($bucket_name, $key)
	{
		try {
			return $this->s3Client->doesObjectExist($bucket_name, $key) ? true : false;
		}
		catch (S3Exception $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model putObject(array $args = array()) {@command S3 PutObject}
	 */
	public function putObject($bucket_name, $key, $source, array $options = array())
	{
		try {
			if (empty($options['ACL'])) {
				$options['ACL'] = strpos($bucket_name, 'readmoo-cf-') === 0 ?
					CannedAcl::PUBLIC_READ :
					CannedAcl::PRIVATE_ACCESS;
			}
			if (empty($options['StorageClass'])) {
				$options['StorageClass'] = strpos($bucket_name, 'readmoo-cf-') === 0 ?
					StorageClass::REDUCED_REDUNDANCY :
					StorageClass::STANDARD;
			}
			$options = array(
				'Bucket' => $bucket_name,
				'Key' => $key
			) + $options;
			if (empty($options['SourceFile']) && empty($options['Body'])) {
				$options[is_file($source) ? 'SourceFile' : 'Body'] = $source;
			}
			if (isset($options['SourceFile']) && empty($options['ContentType'])) {
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$options['ContentType'] = finfo_file($finfo, $options['SourceFile']);
			}
			return $this->s3Client->putObject($options);
		}
		catch (S3Exception $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model copyObject(array $args = array()) {@command S3 CopyObject}
	 */
	public function copyObject($bucket_name, $key, $source, array $options = array())
	{
		try {
			if (empty($options['ACL'])) {
				$options['ACL'] = strpos($bucket_name, 'readmoo-cf-') === 0 ?
					CannedAcl::PUBLIC_READ :
					CannedAcl::PRIVATE_ACCESS;
			}
			if (empty($options['StorageClass'])) {
				$options['StorageClass'] = strpos($bucket_name, 'readmoo-cf-') === 0 ?
					StorageClass::REDUCED_REDUNDANCY :
					StorageClass::STANDARD;
			}
			$options = array(
				'Bucket' => $bucket_name,
				'Key' => $key,
				'CopySource' => $source
			) + $options;
			return $this->s3Client->copyObject($options);
		}
		catch (S3Exception $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model deleteObject(array $args = array()) {@command S3 DeleteObject}
	 */
	public function deleteObject($bucket_name, $s3key)
	{
		try {
			$this->s3Client->deleteObject(array(
				'Bucket' => $bucket_name,
				'Key' =>$s3key
			));
			return true;
		}
		catch (S3Exception $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model deleteObjects(array $args = array()) {@command S3 DeleteObjects}
	 */
	public function deleteObjects($bucket_name, $keys)
	{
		try {
			$this->s3Client->deleteObjects(array(
				'Bucket' => $bucket_name,
				'Objects'=> $keys
			));
			return true;
		}
		catch (S3Exception $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method int deleteMatchingObjects($bucket, $prefix = '', $regex = '', array $options = array()) {@command S3 DeleteMatchingObjects}
	 */
	public function deleteMatchingObjects($bucket_name, $prefix = '', $regex = '', array $options = array())
	{
		try {
			return $this->s3Client->deleteMatchingObjects($bucket_name, $prefix, $regex, $options);
		}
		catch (RuntimeException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	public function registerStreamWrapper()
	{
		return $this->s3Client->registerStreamWrapper();
	}

	/**
	 * @method Model listObjects(array $args = array()) {@command S3 ListObjects}
	 */
	public function listObjects($bucket_name, $prefix = '')
	{
		try {
			return $this->s3Client->listObjects([
				'Bucket' =>  $bucket_name,
				'Prefix' => $prefix
			]);
		}
		catch (RuntimeException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * [createDistribution description]
	 * @param  [type] $bucket_name [description]
	 * @param  [type] $domain_name [description]
	 * @return [type]              [description]
	 */
	public function createDistribution($bucket_name, $domain_name)
	{
		try {
			$return = $this->cfClient->createDistribution($this->_return_distribution_config_array($bucket_name, $domain_name, true));
			return $return->toArray();
		}
		catch (CloudFrontException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	private function _return_distribution_config_array($bucket_name= '', $domain_name = '', $enabled = true)
	{
		$origin_id = 'S3-'.$bucket_name;
		return array(
			'CallerReference' => md5(time()),
			'Aliases' => array(
				'Quantity'=>1,
				'Items'=> array($domain_name)
			),
			'DefaultRootObject' => 'index.html',
			'Origins' => array(
				'Quantity' => 1,
				'Items' => array(
					array(
						'Id' => $origin_id,
						'DomainName' => strtolower($bucket_name.'.s3.amazonaws.com'),
						'S3OriginConfig' => array(
							'OriginAccessIdentity' => 'origin-access-identity/cloudfront/'.$this->cfIdentity
						)
					)
				)
			),
			'DefaultCacheBehavior' => array(
				'TargetOriginId' => $origin_id,
				'ForwardedValues' => array(
					'QueryString' => false
				),
				'TrustedSigners' => array(
					'Enabled' => false,
					'Quantity' => 0,
					'Items' => array()
			),
				'ViewerProtocolPolicy' => ViewerProtocolPolicy::ALLOW_ALL,
				'MinTTL' => 0
			),
			'CacheBehaviors' => array('Quantity' => 0, 'Items' => array()),
			'Comment' => 'Distribution for '.$bucket_name,
			'Logging' => array(
				'Enabled' => false,
				'Bucket' => '',
				'Prefix' => ''
			),
			'Enabled' => $enabled
		);
	}

	public function disableDistribution($cfID)
	{
		try {
			$getConfig = $this->cfClient->getDistributionConfig(array('Id' => $cfID));
			$got_config_array = $getConfig->toArray();
			try {
				$config_array = $got_config_array;
				$config_array['Enabled'] = false;
				$config_array['Id'] = $cfID;
				$config_array['IfMatch'] = $got_config_array['ETag'];
				$config_array['Logging'] = array(
					'Enabled' => false,
					'Bucket' => '',
					'Prefix' => ''
				);
				unset($config_array['ETag']);
				unset($config_array['RequestId']);
				$this->cfClient->updateDistribution($config_array);
			}
			catch (CloudFrontException $e) {
				return $this->debug ? $e->getMessage() : false;
			}
		}
		catch (CloudFrontException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	public function deleteDistribution($cfID)
	{
		try {
			$getDistribution = $this->cfClient->getDistribution(array('Id' => $cfID));
			$got_distribution_array = $getDistribution->toArray();
			if ($got_distribution_array['Status'] == 'Deployed' and $got_distribution_array['DistributionConfig']['Enabled'] == false) {
				try {
					$this->cfClient->deleteDistribution(array('Id' => $cfID, 'IfMatch' => $got_distribution_array['ETag']));
					return true;
				}
				catch (CloudFrontException $e) {
					return $this->debug ? $e->getMessage() : false;
				}
			}
			else {
				return $this->debug ? $e->getMessage() : false;
			}
		}
		catch (CloudFrontException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model getDistribution(array $args = array()) {@command CloudFront GetDistribution}
	 */
	public function getDistribution($cfID)
	{
		try {
			return $this->cfClient->getDistribution(array('Id' => $cfID));
		} catch (CloudFrontException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model listDistributions(array $args = array()) {@command CloudFront ListDistributions}
	 */
	public function listDistributions($cname = false)
	{
		try {
			$distributions = $this->cfClient->listDistributions();
			$result = [];
			if ($cname) {
				foreach ($distributions->get('Items') as $distribution) {
					if ($distribution['Aliases']['Quantity'] > 1) {
						foreach ($distribution['Aliases']['Items'] as $alias) {
							if (preg_match(sprintf('/%s$/', $cname), $alias)) {
								$result[] = $distribution;
								break;
							}
						}
					}
				}
			}
			else {
				$result = $distributions->get('Items');
			}
			return $result;
		} catch (CloudFrontException $e) {
			return $this->debug ? $e->getMessage() : false;
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
			}
			elseif (empty($caller_reference)) {
				$caller_reference = rtrim(base64_encode(sha1(implode("\x01", $paths) . date('Y-m-d H:i:s'))), '=');
				return $this->cfClient->createInvalidation([
					'DistributionId' => $cfID,
					'Paths' => [
						'Quantity' => count($paths),
						'Items' => $paths
					],
					'CallerReference' => $caller_reference
				]);
			}
		} catch (CloudFrontException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}
	/**
	 * Client to interact with Amazon CloudFront
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
			$params = array(
				'QueueName' => ENVIRONMENT . '_' . $queueName
			);
			if (is_array($attributes)) {
				$params['Attributes'] = $attributes;
			}
			$result = $this->sqsClient->createQueue($params);
			return $result->get('QueueUrl');
		}
		catch (SqsException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 *
	 * @method Model getQueueUrl(array $args = array()) {@command Sqs GetQueueUrl}
	 */
	public function getQueueUrl($queueName, $queueOwnerAWSAccountId = false)
	{
		try {
			$params = array(
				'QueueName' => ENVIRONMENT . '_' . $queueName
			);
			if ( ! empty($queueOwnerAWSAccountId)) {
				$params['QueueOwnerAWSAccountId'] = $queueOwnerAWSAccountId;
			}
			$result = $this->sqsClient->getQueueUrl($params);
			return $result->get('QueueUrl');
		}
		catch (SqsException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 *
	 * @method Model listQueues(array $args = array()) {@command Sqs ListQueues}
	 */
	public function listQueues($queueNamePrefix = false)
	{
		try {
			$result = $this->sqsClient->listQueues(
				empty($queueNamePrefix) ?
					array() :
					array('QueueNamePrefix' => ENVIRONMENT . '_' . $queueNamePrefix)
			);
			return $result->get('QueueUrls');
		}
		catch (SqsException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model sendMessage(array $args = array()) {@command Sqs SendMessage}
	 */
	public function sendMessage($queueUrl, $messageBody, $delaySeconds = false)
	{
		try {
			$params = array(
				'QueueUrl' => $queueUrl,
				'MessageBody' => $messageBody
			);
			if ($delaySeconds !== false) {
				$params[QueueAttribute::DELAY_SECONDS] = $delaySeconds;
			}
			$result = $this->sqsClient->sendMessage($params);
			if ($result->get('MD5OfMessageBody') == md5($messageBody)) {
				return $result->get('MessageId');
			}
			else {
				return $this->debug ? 'MD5 of message not matched' : false;
			}
		}
		catch (SqsException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model sendMessageBatch(array $args = array()) {@command Sqs SendMessageBatch}
	 */
	public function sendMessageBatch($queueUrl, $entries)
	{
		try {
			$params = array(
				'QueueUrl' => $queueUrl,
				'Entries' => $entries
			);
			return $this->sqsClient->sendMessageBatch($params);
		}
		catch (SqsException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model receiveMessage(array $args = array()) {@command Sqs ReceiveMessage}
	 */
	public function receiveMessage($queueUrl, $maxNumberOfMessages = false, $visibilityTimeout = false, $waitTimeSeconds = false)
	{
		try {
			$params = array(
				'QueueUrl' => $queueUrl,
				'Attributes' => array(
				)
			);
			if ($maxNumberOfMessages !== false) {
				$params['MaxNumberOfMessages'] = $maxNumberOfMessages;
			}
			if ($visibilityTimeout !== false) {
				$params['VisibilityTimeout'] = $visibilityTimeout;
			}
			if ($waitTimeSeconds !== false) {
				$params['WaitTimeSeconds'] = $waitTimeSeconds;
			}
			$result = $this->sqsClient->receiveMessage($params);
			return $result->get('Messages');
		}
		catch (SqsException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model deleteMessage(array $args = array()) {@command Sqs DeleteMessage}
	 */
	public function deleteMessage($queueUrl, $receiptHandle)
	{
		try {
			$result = $this->sqsClient->deleteMessage(array(
				'QueueUrl' => $queueUrl,
				'ReceiptHandle' => $receiptHandle
			));
			return $result;
		}
		catch (SqsException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model changeMessageVisibility(array $args = array()) {@command Sqs ChangeMessageVisibility}
	 */
	public function changeMessageVisibility($queueUrl, $receiptHandle, $visibilityTimeout)
	{
		try {
			$result = $this->sqsClient->changeMessageVisibility(array(
				'QueueUrl' => $queueUrl,
				'ReceiptHandle' => $receiptHandle,
				'VisibilityTimeout' => $visibilityTimeout
			));
			return $result;
		}
		catch (SqsException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model changeMessageVisibilityBatch(array $args = array()) {@command Sqs ChangeMessageVisibilityBatch}
	 */
	public function changeMessageVisibilityBatch($queueUrl, $entries)
	{
		try {
			return $this->sqsClient->changeMessageVisibilityBatch(array(
				'QueueUrl' => $queueUrl,
				'Entries' => $entries
			));
		}
		catch (SqsException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
	 * @method Model deleteMessageBatch(array $args = array()) {@command Sqs DeleteMessageBatch}
	 */
	public function deleteMessageBatch($queueUrl, $entries)
	{
		try {
			return $this->sqsClient->deleteMessageBatch(array(
				'QueueUrl' => $queueUrl,
				'Entries' => $entries
			));
		}
		catch (SqsException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	public function putItem(array $params = array())
	{
		try {
			return $this->dynamoDbClient->putItem($params);
		} catch (DynamoDbException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	public function queryItem(array $params = array())
	{
		try {
			return $this->dynamoDbClient->query($params);
		} catch (DynamoDbException $e) {
			return $this->debug ? $e->getMessage() : false;
		}
	}

	/**
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
}
// END Aws_lib Class

/* End of file Aws_lib.php */
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
use Aws\S3\Exception\S3Exception;
use Aws\CloudFront\Enum\OriginProtocolPolicy;
use Aws\CloudFront\Enum\ViewerProtocolPolicy;
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\Sqs\Enum\QueueAttribute;
use Aws\Sqs\Exception\SqsException;

class Aws_lib {
	private $aws;
	private $s3Client;
	private $cfClient;
	private $sqsClient;
	private $cfIdentity;
	public $debug = FALSE;

	function __construct()
	{
		$this->aws = Aws::factory(
			is_readable(APPPATH . 'config/' . ENVIRONMENT . '/aws_lib.php') ?
			APPPATH . 'config/' . ENVIRONMENT . '/aws_lib.php' :
			APPPATH . 'config/aws_lib.php'
		);
		$this->s3Client = $this->aws->get('S3');
		$this->cfClient = $this->aws->get('CloudFront');
		$this->sqsClient = $this->aws->get('Sqs');
	}

	public function isValidBucketName($bucket_name)
	{
		try
		{
			return $this->s3Client->isValidBucketName($bucket_name) ? TRUE : FALSE;
		}
		catch (S3Exception $e) 
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	public function doesBucketExist($bucket_name)
	{
		try
		{
			return $this->s3Client->doesBucketExist($bucket_name) ? TRUE : FALSE;
		}
		catch (S3Exception $e) 
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model createBucket(array $args = array()) {@command S3 CreateBucket}
	 */
	public function createBucket($bucket_name)
	{
		if ($this->isValidBucketName($bucket_name))
		{
			if ($this->doesBucketExist($bucket_name))
				return FALSE;
			else
			{
				try
				{
					$this->s3Client->createBucket(
					array(
						'Bucket'=> $bucket_name,
						'ACL'  => CannedAcl::PUBLIC_READ
						//add more items if required here
					));
					return TRUE;
				}
				catch (S3Exception $e)
				{
					return $this->debug ? $e->getMessage() : FALSE;
				}
			}
		}
		else
			return $this->debug ? $e->getMessage() : FALSE;
	}

	/**
	 * @method Model headBucket(array $args = array()) {@command S3 HeadBucket}
 	 */
	public function headBucket($bucket_name)
	{
		try
		{
			return $this->s3Client->headBucket(array(
				'Bucket' => $bucket_name
			));
		}
		catch (S3Exception $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model headObject(array $args = array()) {@command S3 HeadObject}
 	 */
	public function headObject($bucket_name, $key, $args = array())
	{
		try
		{
			$args['Bucket'] = $bucket_name;
			$args['Key'] = $key;
			return $this->s3Client->headObject($args);
		}
		catch (S3Exception $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
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
		if ($this->doesBucketExist($bucket_name))
		{
			try
			{
				$this->s3Client->putBucketPolicy(
				array(
					'Bucket'=> $bucket_name,
					'Policy'=> $this->_return_bucket_policy($bucket_name)
				));
				return TRUE;
			}
			catch (S3Exception $e)
			{
				return $this->debug ? $e->getMessage() : FALSE;
			}
		}
		else
			return FALSE;
	}

	/**
	 * @method Model deleteBucket(array $args = array()) {@command S3 DeleteBucket}
	 */
	public function deleteBucket($bucket_name)
	{
		try
		{
			$this->s3Client->clearBucket($bucket_name);
			$this->s3Client->deleteBucket(array(
				'Bucket' => $bucket_name
			));
			return TRUE;
		}
		catch (S3Exception $e) 
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	public function doesObjectExist($bucket_name, $key)
	{
		try
		{
			return $this->s3Client->doesObjectExist($bucket_name, $key) ? TRUE : FALSE;
		}
		catch (S3Exception $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model putObject(array $args = array()) {@command S3 PutObject}
	 */
	public function putObject($bucket_name, $key, $body)
	{
		try
		{
			return $this->s3Client->putObject(array(
				'Bucket' => $bucket_name,
				'Key' => $key,
				'Body' => $body,
				'ACL' => CannedAcl::PUBLIC_READ
			));
		}
		catch (S3Exception $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model deleteObject(array $args = array()) {@command S3 DeleteObject}
	 */
	public function deleteObject($bucket_name, $s3key)
	{
		try
		{
			$this->s3Client->deleteObject(array(
				'Bucket' => $bucket_name,
				'Key' =>$s3key
			));
			return true;
		}
		catch (S3Exception $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model deleteObjects(array $args = array()) {@command S3 DeleteObjects}
	 */
	public function deleteObjects($bucket_name, $keys)
	{
		try
		{
			$this->s3Client->deleteObjects(array(
				'Bucket' => $bucket_name,
				'Objects'=> $keys
			));
			return true;
		}
		catch (S3Exception $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	public function registerStreamWrapper()
	{
		return $this->s3Client->registerStreamWrapper();
	}
/**
 * Client to interact with Amazon Simple Storage Service
 *
 * @method \Aws\S3\S3SignatureInterface getSignature()
 * @method Model abortMultipartUpload(array $args = array()) {@command S3 AbortMultipartUpload}
 * @method Model completeMultipartUpload(array $args = array()) {@command S3 CompleteMultipartUpload}
 * @method Model copyObject(array $args = array()) {@command S3 CopyObject}
 * @method Model createMultipartUpload(array $args = array()) {@command S3 CreateMultipartUpload}
 * @method Model deleteBucket(array $args = array()) {@command S3 DeleteBucket}
 * @method Model deleteBucketCors(array $args = array()) {@command S3 DeleteBucketCors}
 * @method Model deleteBucketLifecycle(array $args = array()) {@command S3 DeleteBucketLifecycle}
 * @method Model deleteBucketPolicy(array $args = array()) {@command S3 DeleteBucketPolicy}
 * @method Model deleteBucketTagging(array $args = array()) {@command S3 DeleteBucketTagging}
 * @method Model deleteBucketWebsite(array $args = array()) {@command S3 DeleteBucketWebsite}
 * @method Model getBucketAcl(array $args = array()) {@command S3 GetBucketAcl}
 * @method Model getBucketCors(array $args = array()) {@command S3 GetBucketCors}
 * @method Model getBucketLifecycle(array $args = array()) {@command S3 GetBucketLifecycle}
 * @method Model getBucketLocation(array $args = array()) {@command S3 GetBucketLocation}
 * @method Model getBucketLogging(array $args = array()) {@command S3 GetBucketLogging}
 * @method Model getBucketNotification(array $args = array()) {@command S3 GetBucketNotification}
 * @method Model getBucketPolicy(array $args = array()) {@command S3 GetBucketPolicy}
 * @method Model getBucketRequestPayment(array $args = array()) {@command S3 GetBucketRequestPayment}
 * @method Model getBucketTagging(array $args = array()) {@command S3 GetBucketTagging}
 * @method Model getBucketVersioning(array $args = array()) {@command S3 GetBucketVersioning}
 * @method Model getBucketWebsite(array $args = array()) {@command S3 GetBucketWebsite}
 * @method Model getObject(array $args = array()) {@command S3 GetObject}
 * @method Model getObjectAcl(array $args = array()) {@command S3 GetObjectAcl}
 * @method Model getObjectTorrent(array $args = array()) {@command S3 GetObjectTorrent}
 * @method Model listBuckets(array $args = array()) {@command S3 ListBuckets}
 * @method Model listMultipartUploads(array $args = array()) {@command S3 ListMultipartUploads}
 * @method Model listObjectVersions(array $args = array()) {@command S3 ListObjectVersions}
 * @method Model listObjects(array $args = array()) {@command S3 ListObjects}
 * @method Model listParts(array $args = array()) {@command S3 ListParts}
 * @method Model putBucketAcl(array $args = array()) {@command S3 PutBucketAcl}
 * @method Model putBucketCors(array $args = array()) {@command S3 PutBucketCors}
 * @method Model putBucketLifecycle(array $args = array()) {@command S3 PutBucketLifecycle}
 * @method Model putBucketLogging(array $args = array()) {@command S3 PutBucketLogging}
 * @method Model putBucketNotification(array $args = array()) {@command S3 PutBucketNotification}
 * @method Model putBucketRequestPayment(array $args = array()) {@command S3 PutBucketRequestPayment}
 * @method Model putBucketTagging(array $args = array()) {@command S3 PutBucketTagging}
 * @method Model putBucketVersioning(array $args = array()) {@command S3 PutBucketVersioning}
 * @method Model putBucketWebsite(array $args = array()) {@command S3 PutBucketWebsite}
 * @method Model putObjectAcl(array $args = array()) {@command S3 PutObjectAcl}
 * @method Model restoreObject(array $args = array()) {@command S3 RestoreObject}
 * @method Model uploadPart(array $args = array()) {@command S3 UploadPart}
 * @method Model uploadPartCopy(array $args = array()) {@command S3 UploadPartCopy}
 * @method waitUntilBucketExists(array $input) Wait until a bucket exists. The input array uses the parameters of the HeadBucket operation and waiter specific settings
 * @method waitUntilBucketNotExists(array $input) Wait until a bucket does not exist. The input array uses the parameters of the HeadBucket operation and waiter specific settings
 * @method waitUntilObjectExists(array $input) Wait until an object exists. The input array uses the parameters of the HeadObject operation and waiter specific settings
 * @method ResourceIteratorInterface getListBucketsIterator(array $args = array()) The input array uses the parameters of the ListBuckets operation
 * @method ResourceIteratorInterface getListMultipartUploadsIterator(array $args = array()) The input array uses the parameters of the ListMultipartUploads operation
 * @method ResourceIteratorInterface getListObjectsIterator(array $args = array()) The input array uses the parameters of the ListObjects operation
 * @method ResourceIteratorInterface getListObjectVersionsIterator(array $args = array()) The input array uses the parameters of the ListObjectVersions operation
 * @method ResourceIteratorInterface getListPartsIterator(array $args = array()) The input array uses the parameters of the ListParts operation
 *
 * @link http://docs.aws.amazon.com/aws-sdk-php/guide/latest/service-s3.html User guide
 * @link http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.S3.S3Client.html API docs
 */

	public function createDistribution($bucket_name, $domain_name)
	{
		try
		{
			$return = $this->cfClient->createDistribution($this->_return_distribution_config_array($bucket_name, $domain_name, TRUE));
			return $return->toArray();
		}
		catch (CloudFrontException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	private function _return_distribution_config_array($bucket_name= '', $domain_name = '', $enabled = TRUE)
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
					'QueryString' => FALSE
				),
				'TrustedSigners' => array(
					'Enabled' => FALSE,
					'Quantity' => 0,
					'Items' => array()
			),
				'ViewerProtocolPolicy' => ViewerProtocolPolicy::ALLOW_ALL,
				'MinTTL' => 0
			),
			'CacheBehaviors' => array('Quantity' => 0, 'Items' => array()),
			'Comment' => 'Distribution for '.$bucket_name,
			'Logging' => array(
				'Enabled' => FALSE,
				'Bucket' => '',
				'Prefix' => ''
			),
			'Enabled' => $enabled
		);
	}

	public function disableDistribution($cfID)
	{
		try
		{
			$getConfig = $this->cfClient->getDistributionConfig(array('Id' => $cfID));
			$got_config_array = $getConfig->toArray();
			try
			{
				$config_array = $got_config_array;
				$config_array['Enabled'] = FALSE;
				$config_array['Id'] = $cfID;
				$config_array['IfMatch'] = $got_config_array['ETag'];
				$config_array['Logging'] = array(
					'Enabled' => FALSE,
					'Bucket' => '',
					'Prefix' => ''
				);
				unset($config_array['ETag']);
				unset($config_array['RequestId']);
				$this->cfClient->updateDistribution($config_array);
			}
			catch (CloudFrontException $e)
			{
				return $this->debug ? $e->getMessage() : FALSE;
			}
		}
		catch (CloudFrontException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	public function deleteDistribution($cfID)
	{
		try
		{
			$getDistribution = $this->cfClient->getDistribution(array('Id' => $cfID));
			$got_distribution_array = $getDistribution->toArray();
			if ($got_distribution_array['Status'] == 'Deployed' and $got_distribution_array['DistributionConfig']['Enabled'] == FALSE)
			{
				try
				{
					$this->cfClient->deleteDistribution(array('Id' => $cfID, 'IfMatch' => $got_distribution_array['ETag']));
					return TRUE;
				}
				catch (CloudFrontException $e)
				{
					return $this->debug ? $e->getMessage() : FALSE;
				}
			}
			else
				return $this->debug ? $e->getMessage() : FALSE;
		}
		catch (CloudFrontException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model createQueue(array $args = array()) {@command Sqs CreateQueue}
	 */
 	public function createQueue($queueName, $attributes = FALSE)
	{
		try
		{
			$params = array('QueueName' => ENVIRONMENT . '_' . $queueName);
			if (is_array($attributes)) $params['Attributes'] = $attributes;
			$result = $this->sqsClient->createQueue($params);
			return $result->get('QueueUrl');
		}
		catch (SqsException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 *
	 * @method Model listQueues(array $args = array()) {@command Sqs ListQueues}
	 */
	public function listQueues($queueNamePrefix = FALSE)
	{
		try
		{
			$result = $this->sqsClient->listQueues(empty($queueNamePrefix) ? array() : array('QueueNamePrefix' => ENVIRONMENT . '_' . $queueNamePrefix));
			return $result->get('QueueUrls');
		}
		catch (SqsException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model sendMessage(array $args = array()) {@command Sqs SendMessage}
	 */
	public function sendMessage($queueUrl, $messageBody, $delaySeconds = FALSE)
	{
		try
		{
			$params = array(
				'QueueUrl' => $queueUrl,
				'MessageBody' => $messageBody
			);
			if ($delaySeconds !== FALSE) $params[QueueAttribute::DELAY_SECONDS] = $delaySeconds;
			$result = $this->sqsClient->sendMessage($params);
			if ($result->get('MD5OfMessageBody') == md5($messageBody))
				return $result->get('MessageId');
			else
				return $this->debug ? 'MD5 of message not matched' : FALSE;
		}
		catch (SqsException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model sendMessageBatch(array $args = array()) {@command Sqs SendMessageBatch}
	 */
	public function sendMessageBatch($queueUrl, $entries)
	{
		try
		{
			$params = array(
				'QueueUrl' => $queueUrl,
				'Entries' => $entries
			);
			return $this->sqsClient->sendMessageBatch($params);
		}
		catch (SqsException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model receiveMessage(array $args = array()) {@command Sqs ReceiveMessage}
	 */
	public function receiveMessage($queueUrl, $maxNumberOfMessages = FALSE, $visibilityTimeout = FALSE, $waitTimeSeconds = FALSE)
	{
		try
		{
			$params = array(
				'QueueUrl' => $queueUrl,
				'Attributes' => array(
				)
			);
			if ($maxNumberOfMessages !== FALSE) $params['MaxNumberOfMessages'] = $maxNumberOfMessages;
			if ($visibilityTimeout !== FALSE) $params['VisibilityTimeout'] = $visibilityTimeout;
			if ($waitTimeSeconds !== FALSE) $params['WaitTimeSeconds'] = $waitTimeSeconds;
			$result = $this->sqsClient->receiveMessage($params);
			return $result->get('Messages');
		}
		catch (SqsException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model deleteMessage(array $args = array()) {@command Sqs DeleteMessage}
	 */
	public function deleteMessage($queueUrl, $receiptHandle)
	{
		try
		{
			$result = $this->sqsClient->deleteMessage(array(
				'QueueUrl' => $queueUrl,
				'ReceiptHandle' => $receiptHandle
			));
			return $result;
		}
		catch (SqsException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model changeMessageVisibility(array $args = array()) {@command Sqs ChangeMessageVisibility}
	 */
	public function changeMessageVisibility($queueUrl, $receiptHandle, $visibilityTimeout)
	{
		try
		{
			$result = $this->sqsClient->changeMessageVisibility(array(
				'QueueUrl' => $queueUrl,
				'ReceiptHandle' => $receiptHandle,
				'VisibilityTimeout' => $visibilityTimeout
			));
			return $result;
		}
		catch (SqsException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model changeMessageVisibilityBatch(array $args = array()) {@command Sqs ChangeMessageVisibilityBatch}
	 */
	public function changeMessageVisibilityBatch($queueUrl, $entries)
	{
		try
		{
			return $this->sqsClient->changeMessageVisibilityBatch(array(
				'QueueUrl' => $queueUrl,
				'Entries' => $entries
			));
		}
		catch (SqsException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @method Model deleteMessageBatch(array $args = array()) {@command Sqs DeleteMessageBatch}
	 */
	public function deleteMessageBatch($queueUrl, $entries)
	{
		try
		{
			return $this->sqsClient->deleteMessageBatch(array(
				'QueueUrl' => $queueUrl,
				'Entries' => $entries
			));
		}
		catch (SqsException $e)
		{
			return $this->debug ? $e->getMessage() : FALSE;
		}
	}

	/**
	 * @class SqsClient
	 *
	 * @method Model addPermission(array $args = array()) {@command Sqs AddPermission}
	 * @method Model changeMessageVisibilityBatch(array $args = array()) {@command Sqs ChangeMessageVisibilityBatch}
	 * @method Model deleteQueue(array $args = array()) {@command Sqs DeleteQueue}
	 * @method Model getQueueAttributes(array $args = array()) {@command Sqs GetQueueAttributes}
	 * @method Model getQueueUrl(array $args = array()) {@command Sqs GetQueueUrl}
	 * @method Model removePermission(array $args = array()) {@command Sqs RemovePermission}
	 * @method Model setQueueAttributes(array $args = array()) {@command Sqs SetQueueAttributes}
	 * @method ResourceIteratorInterface getListQueuesIterator(array $args = array()) The input array uses the parameters of the ListQueues operation
	 */
}
// END Aws_lib Class

/* End of file Aws_lib.php */
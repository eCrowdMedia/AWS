<?php

/**
 * Aws_lib 的例外翻譯層（anti-corruption layer）。
 *
 * 設計原則：在套件邊界把 AWS SDK 的例外翻譯成本套件定義的少數幾種型別，
 * 保留原始例外於 getPrevious()，並用 marker interface 標示「可重試」，
 * 讓上層的重試／降級機制只需判斷 `instanceof RetryableAwsFailure`，
 * 不必在各呼叫端各自維護一份 AWS error code 清單。
 *
 * 為什麼不是回傳 false：
 *   1.39.12 曾讓 DynamoDB 方法失敗時回傳 false。那等於在邊界上把一個有型別、
 *   帶 cause、帶 getAwsErrorCode() 的例外降級成沒有型別、沒有 cause、沒有分類的
 *   sentinel，並把「這是什麼錯／該不該重試／該不該當成查無資料」的判斷責任
 *   推給每一個呼叫端。實務結果是同一段守衛被貼到十餘處、卻有六種不同的善後，
 *   且 ValidationException（我們自己的 bug）會被靜默當成「查無資料」。
 *   1.40.0 改為翻譯後上拋，回到「要嘛給你 Result、要嘛拋例外」的契約。
 *
 * 載入方式：本套件型別為 codeigniter-library，安裝時整個目錄被複製到 CI 的
 * libraries 路徑下、不在 vendor/ 內，因此**沒有 PSR-4 autoload**可用。
 * 由 Aws_lib.php 開頭的 require_once 明確載入。
 *
 * @category    Libraries
 *
 * @author      Willy
 *
 * @link        https://readmoo.com
 */

namespace Ecrowdmedia\Aws\Exception;

use Aws\Exception\AwsException;

/**
 * 總類 marker：本套件在邊界翻譯出來的所有操作失敗。
 *
 * 呼叫端若想「不管哪一種 AWS 失敗都一律降級」，catch 這個即可；
 * 想區分可重試與否，再往下看 RetryableAwsFailure。
 */
interface AwsOperationFailed extends \Throwable
{
    /** 失敗的 Aws_lib 方法名，例如 putItem */
    public function getOperation(): string;

    /** AWS error code，例如 ThrottlingException；SDK 未提供時為空字串 */
    public function getAwsErrorCode(): string;
}

/**
 * marker：暫時性失敗，重試（在更高層，例如 job／queue）有機會成功。
 *
 * ⚠️ 不代表「應該在這裡立刻重試」。AWS SDK 本身已內建 retry middleware
 * （實測 3.384.7 預設 legacy mode、最多 3 次嘗試），所以走到這個例外時
 * SDK 已經重試過了。是否要在應用層再疊一層，見 eCrowdMedia/AWS#16。
 */
interface RetryableAwsFailure extends AwsOperationFailed
{
}

/**
 * AWS 失敗的三種語意分類，與服務無關（DynamoDB／S3／SES… 共用）。
 *
 * 分類依據 Eric Lippert 的 exception 四分法：
 *   Expected  → vexing：API 逼你用例外表達的正常結果（條件式寫入沒命中）
 *   Transient → exogenous：外部環境造成，必須處理（throttling、5xx、連線）
 *   Defect    → boneheaded：我們自己的 bug 或設定錯（ValidationException、
 *               ResourceNotFound、AccessDenied），不該被當成「查無資料」吞掉
 */
enum AwsFailureCategory: string
{
    case Expected = 'expected';
    case Transient = 'transient';
    case Defect = 'defect';

    /**
     * 可重試／暫時性錯誤碼。
     *
     * 以 AWS SDK 自己的 `RetryMiddleware::$retryCodes` 為基準（vendor/aws/aws-sdk-php/
     * src/RetryMiddleware.php），避免我方另外維護一份殘缺的清單——那正是本套件
     * 要消除的問題。前 11 個與 SDK 完全一致，之後是我方補充。
     *
     * @var array<int, string>
     */
    public const TRANSIENT_CODES = [
        // ↓ 與 SDK RetryMiddleware::$retryCodes 同步
        'RequestLimitExceeded',
        'Throttling',
        'ThrottlingException',
        'ThrottledException',
        'ProvisionedThroughputExceededException',
        'RequestThrottled',
        'BandwidthLimitExceeded',
        'RequestThrottledException',
        'TooManyRequestsException',
        'IDPCommunicationError',
        'EC2ThrottledException',
        // ↓ 我方補充：SDK 表沒有但確實可重試的 5xx 類
        'InternalServerError',
        'InternalFailure',
        'ServiceUnavailable',
        'RequestTimeout',
        // ↓ DynamoDB 專屬，依 AWS「Error handling with DynamoDB」的
        //   「OK to retry? Yes」欄位
        'LimitExceededException',            // control-plane 並發限制
        'ReplicatedWriteConflictException',  // MRSC global table 跨區改同一筆
        // ↓ 交易併發衝突。單筆 putItem／updateItem／deleteItem 撞上同一筆 item
        //   正在進行的交易時會回這個（AWS 另有 TransactionConflict 這個
        //   CloudWatch metric），屬暫時性、退避後重試即可。
        'TransactionConflictException',
        // ↓ 同一個 ClientRequestToken 的交易已在進行中。AWS 的建議處理方式
        //   正是「讓 client 重試以觸發原請求完成」，但需留 5 秒以上間隔。
        'TransactionInProgressException',
    ];

    /**
     * 刻意**不**收的可重試碼。
     *
     * AWS 文件把 `ItemCollectionSizeLimitExceededException` 標成「OK to retry? Yes」，
     * 但那是 LSI 下同一個 partition key 的 item collection 超過 10 GB —— 重試不會
     * 讓它變小，屬資料模型問題。歸進 Transient 會讓它被當成「等一下就好」而被忽略，
     * 留在 Defect 反而會被看見。
     *
     * `TransactionCanceledException` 的可重試性要看 CancellationReasons 內每一筆的
     * Code（TransactionConflict 可重試、ConditionalCheckFailed 不可），本層不解析
     * 那個結構。需要區分的呼叫端請檢查 getPrevious()。
     *
     * @var array<int, string>
     */
    public const DELIBERATELY_NOT_TRANSIENT = [
        'ItemCollectionSizeLimitExceededException',
        'TransactionCanceledException',
    ];

    /**
     * 可重試的 HTTP status。
     *
     * 500/502/503/504 與 SDK 的 `RetryMiddleware::$retryStatusCodes` 一致；
     * 429 是我方補充——canonical 的 throttling status，SDK 主要靠 error code
     * 認 throttling，但 gateway 直接回 429 而不帶 x-amzn-ErrorType 時就會漏接，
     * 與 502/503 是同一個機制的洞。
     *
     * @var array<int, int>
     */
    public const TRANSIENT_STATUS_CODES = [429, 500, 502, 503, 504];

    public static function of(AwsException $e): self
    {
        $code = (string) $e->getAwsErrorCode();

        if ($code === 'ConditionalCheckFailedException') {
            return self::Expected;
        }

        if (in_array($code, self::TRANSIENT_CODES, true) || $e->isConnectionError()) {
            return self::Transient;
        }

        // ⚠️ 不能只看 error code：gateway 層回的 502/503 常常沒有 x-amzn-ErrorType
        // header，此時 getAwsErrorCode() 是 null → (string) null === '' → 會落進
        // Defect，被當成「我們自己的 bug」而且不可重試。SDK 本身也是 code 與
        // status 兩條都看（$retryCodes + $retryStatusCodes），這裡比照。
        if (in_array((int) $e->getStatusCode(), self::TRANSIENT_STATUS_CODES, true)) {
            return self::Transient;
        }

        // 刻意不歸進 Transient 的碼與理由見 DELIBERATELY_NOT_TRANSIENT。
        return self::Defect;
    }

    /** 寫進 log 訊息的中文標籤，可用來 grep 或建 metric filter */
    public function label(): string
    {
        return match ($this) {
            self::Expected => '條件不成立（預期）',
            self::Transient => '暫時性失敗（可重試）',
            self::Defect => '失敗',
        };
    }

    /**
     * CodeIgniter log level。
     *
     * ⚠️ 只能用 ERROR/DEBUG/INFO/ALL——CI 的 `system/core/Log.php` 的 `$_levels`
     * **沒有 WARNING**；且 Galao 各 app 的 `log_threshold = 1`（只寫 ERROR），
     * info/debug 一律不落地。Expected 刻意用 info（＝丟棄），因為條件式寫入
     * 沒命中是正常結果、不該產生噪音；其餘用 error 並靠 label() 區分。
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::Expected => 'info',
            self::Transient, self::Defect => 'error',
        };
    }
}

/**
 * 訊息格式的單一來源。
 *
 * `[table=...]` 這個後綴原本在四處各寫一份（_log_aws_error、
 * _translate_credentials_error、during、afterRetries），改格式時很容易漏改。
 */
final class AwsMessageFormat
{
    public static function tableSuffix(?string $table): string
    {
        return $table === null || $table === '' ? '' : ' [table=' . $table . ']';
    }
}

/**
 * 本套件翻譯出來的 AWS 操作失敗基底。
 *
 * exception code 固定 0：與 AwsException::getCode() 一致（實測為 0，故行為與
 * 1.39.12 之前例外直接上拋時完全相同），也刻意不把 HTTP status 塞進 code——
 * HTTP 對應是 controller／error handler 的責任，library 層不該知道 502。
 */
abstract class AwsOperationException extends \RuntimeException implements AwsOperationFailed
{
    /** 服務名，用於組訊息；由子類覆寫 */
    protected const SERVICE = 'AWS';

    // 建構子標 final：子類不得改簽章，`new static()` 才是安全的
    // （PHPStan new.static）。要建立實例請走 during()／afterRetries() 等
    // named constructor，訊息格式與 context 因此集中在一處。
    final protected function __construct(
        string $message,
        private readonly string $operation,
        private readonly string $awsErrorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Named constructor：由 SDK 例外翻譯而來，原始例外保留在 getPrevious()。
     *
     * ⚠️ 只能在具體子類上呼叫。在抽象基底上直接呼叫會是
     * `Error: Cannot instantiate abstract class`——翻譯請一律走
     * `Aws_lib::_translate_dynamodb_error()`，它的 match 會挑正確的子類。
     *
     * 訊息刻意標示服務名（static::SERVICE，例如「DynamoDB 暫時性失敗」），
     * 而 `Aws_lib::_log_aws_error()` 寫的是服務無關的「AWS 暫時性失敗」——
     * 後者要同時服務 S3／SES 等約 37 處尚未收攏的 catch，故兩邊的標籤
     * 前綴不同，其餘（operation、table、error code、原訊息）一致。
     */
    public static function during(
        string $operation,
        AwsException $e,
        ?string $table = null,
        ?AwsFailureCategory $category = null,
    ): static {
        $code = (string) $e->getAwsErrorCode();

        return new static(
            sprintf(
                'Aws_lib::%s %s %s%s: %s',
                $operation,
                static::SERVICE,
                // 呼叫端已經算過分類時傳進來，避免對同一個例外重複算三次
                ($category ?? AwsFailureCategory::of($e))->label(),
                AwsMessageFormat::tableSuffix($table),
                $code !== '' ? $code . ' - ' . $e->getMessage() : $e->getMessage()
            ),
            $operation,
            $code,
            $e
        );
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getAwsErrorCode(): string
    {
        return $this->awsErrorCode;
    }
}

/**
 * DynamoDB 暫時性失敗：throttling、容量不足、5xx、連線錯誤。
 *
 * 呼叫端通常應該讓它上拋（讓請求失敗、cron 非零退出），而不是當成「查無資料」。
 * 需要降級的話請明確 catch 這個型別，不要 catch 總類。
 */
final class DynamoDbUnavailable extends AwsOperationException implements RetryableAwsFailure
{
    protected const SERVICE = 'DynamoDB';
}

/**
 * DynamoDB 拒絕了這個請求：ValidationException、ResourceNotFoundException、
 * AccessDeniedException 等。
 *
 * 這類幾乎都是我們自己的 bug 或環境設定錯（Lippert 的 boneheaded）。
 * **不該被 catch 後當成查無資料** —— 那會讓程式錯誤永遠沒有人發現。
 */
final class DynamoDbRequestRejected extends AwsOperationException
{
    protected const SERVICE = 'DynamoDB';
}

/**
 * ConditionExpression 不成立（ConditionalCheckFailedException）。
 *
 * 在條件式寫入裡這是**預期的正常結果**而非錯誤，例如
 * 「只在 device_id 還沒填時才更新」沒命中代表已經有人填了。
 * 呼叫端應明確 catch 這個型別並回報「沒有寫入」，而不是靠比對
 * 例外訊息字串（`str_contains($e->getMessage(), 'ConditionalCheckFailed')`）。
 */
final class DynamoDbConditionFailed extends AwsOperationException
{
    protected const SERVICE = 'DynamoDB';
}

/**
 * 無法取得 AWS 憑證。
 *
 * 兩種來源：
 *   - 帶 Fibonacci 重試迴圈的六個方法，重試跑完仍是 CredentialsException
 *     （1.39.12 之前這裡是 `return false`）
 *   - 沒有重試迴圈的四個方法（getIterator／queryBatchItem／putBatchItem／
 *     queryScan），單次嘗試即失敗。這四處在 1.40.0 之前會讓原始的
 *     CredentialsException 未經翻譯逸出——既不是 AwsOperationFailed 也不帶
 *     RetryableAwsFailure，導致「上層只判斷 instanceof RetryableAwsFailure」
 *     的重試機制對這四個方法靜默失效。
 *
 * CredentialsException 並非 AwsException 的子類（直接 extends RuntimeException），
 * 故不走 during()。
 */
final class AwsCredentialsUnavailable extends AwsOperationException implements RetryableAwsFailure
{
    public static function afterRetries(
        string $operation,
        int $attempts,
        ?\Throwable $previous = null,
        ?string $table = null,
    ): self {
        return new self(
            sprintf(
                'Aws_lib::%s 無法取得 AWS 憑證（已嘗試 %d 次）%s: %s',
                $operation,
                $attempts,
                AwsMessageFormat::tableSuffix($table),
                $previous?->getMessage() ?? 'CredentialsException'
            ),
            $operation,
            'CredentialsException',
            $previous
        );
    }
}

/* End of file Aws_exceptions.php */
/* Location: ./Aws_exceptions.php */

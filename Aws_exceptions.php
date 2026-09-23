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
 *   1.39.13 改為翻譯後上拋，回到「要嘛給你 Result、要嘛拋例外」的契約。
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
        // ↓ DynamoDB control-plane 並發限制，AWS 文件明確標示可重試
        'LimitExceededException',
    ];

    /**
     * 可重試的 HTTP status，與 SDK 的 `RetryMiddleware::$retryStatusCodes` 一致。
     *
     * @var array<int, int>
     */
    public const TRANSIENT_STATUS_CODES = [500, 502, 503, 504];

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

        // 已知限制：TransactionCanceledException 的可重試性要看 CancellationReasons
        // 裡每一筆的 Code（TransactionConflict 可重試、ConditionalCheckFailed 不可），
        // 本層不解析那個結構。需要區分的呼叫端請自行檢查 getPrevious()。
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
     * 訊息格式與 Aws_lib 寫進 log 的那一行一致，便於把例外與 log 對照。
     */
    public static function during(string $operation, AwsException $e, ?string $table = null): static
    {
        $code = (string) $e->getAwsErrorCode();

        return new static(
            sprintf(
                'Aws_lib::%s %s %s%s: %s',
                $operation,
                static::SERVICE,
                AwsFailureCategory::of($e)->label(),
                $table === null ? '' : ' [table=' . $table . ']',
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
 * 重試後仍無法取得 AWS 憑證。
 *
 * 對應 Aws_lib 各 DynamoDB 方法的 Fibonacci 重試迴圈跑完仍是
 * CredentialsException 的情況（1.39.12 之前這裡是 `return false`）。
 * CredentialsException 並非 AwsException 的子類，故不走 during()。
 */
final class AwsCredentialsUnavailable extends AwsOperationException implements RetryableAwsFailure
{
    public static function afterRetries(
        string $operation,
        int $attempts,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'Aws_lib::%s 無法取得 AWS 憑證（已嘗試 %d 次）: %s',
                $operation,
                $attempts,
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

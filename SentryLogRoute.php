<?php
namespace Skillshare\YiiSentry;

use CLogger;
use CLogRoute;
use Sentry\ClientInterface;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;
use Yii;

class SentryLogRoute extends CLogRoute
{
	/**
	 * @var string Component ID of the sentry client that should be used to send the logs
	 */
	public $sentryComponent = 'sentry';

	/**
	 * @see self::getStackTrace().
	 * @var string
	 */
	public $tracePattern = '/#(?<number>\d+) (?<file>[^(]+)\((?<line>\d+)\): (?<cls>[^-]+)(->|::)(?<func>[^\(]+)/m';

	/**
	 * Raven_Client instance from SentryComponent->getRaven();
	 * @var ClientInterface
	 */
	protected $raven;

	public static function getSeverityFromLogLevel(string $level): Severity
	{
		$severityLevels = [
			CLogger::LEVEL_PROFILE => Severity::debug(),
			CLogger::LEVEL_TRACE   => Severity::debug(),
			'debug'                => Severity::debug(),
			CLogger::LEVEL_INFO    => Severity::info(),
			CLogger::LEVEL_WARNING => Severity::warning(),
			'warn'                 => Severity::warning(),
			CLogger::LEVEL_ERROR   => Severity::error(),
			'fatal'                => Severity::fatal(),
		];

		$level = strtolower($level);
		if (array_key_exists($level, $severityLevels)) {
			return $severityLevels[$level];
		}

		return Severity::error();
	}

	/**
	 * @inheritdoc
	 */
	protected function processLogs($logs)
	{
		if (count($logs) == 0) {
			return;
		}

		if (!$raven = $this->getRaven()) {
			return;
		}

		SentrySdk::getCurrentHub()->withScope(function (Scope $scope) use ($raven, $logs) {
			foreach ($logs as $log) {
				[$message, $level, $category, $timestamp] = $log;

				$title = preg_replace('#Stack trace:.+#s', '', $message); // remove stack trace from title
				// ensure %'s in messages aren't interpreted as replacement
				// characters by the vsprintf inside raven
				$title = str_replace('%', '%%', $title);

				$scope->setExtras([
					'category'  => $category,
					'timestamp' => $timestamp, // TODO: I dont know if this has en effect
				]);
				$raven->captureMessage($title, self::getSeverityFromLogLevel($level), $scope);
			}
		});
	}

	/**
	 * Parse yii stack trace for sentry.
	 *
	 * Example log string:
	 * #22 /var/www/example.is74.ru/vendor/yiisoft/yii/framework/web/CWebApplication.php(282): CController->run('index')
	 * @param string $log
	 * @return array|string
	 */
	protected function getStackTrace($log)
	{
		$stack = array();
		if (strpos($log, 'Stack trace:') !== false) {
			if (preg_match_all($this->tracePattern, $log, $m, PREG_SET_ORDER)) {
				$stack = array();
				foreach ($m as $row) {
					$stack[] = array(
						'file' => $row['file'],
						'line' => $row['line'],
						'function' => $row['func'],
						'class' => $row['cls'],
					);
				}
			}

		}

		return $stack;
	}

	/**
	 * Return Raven_Client instance or false if error.
	 *
	 * @return ClientInterface|bool
	 */
	protected function getRaven()
	{
		if (!isset($this->raven)) {
			$this->raven = false;
			if (!Yii::app()->hasComponent($this->sentryComponent)) {
				Yii::log("'$this->sentryComponent' does not exist", CLogger::LEVEL_TRACE, __CLASS__);
			} else {
				/** @var SentryComponent $sentry */
				$sentry = Yii::app()->{$this->sentryComponent};
				if (!$sentry || !$sentry->getIsInitialized()) {
					Yii::log("'$this->sentryComponent' not initialised", CLogger::LEVEL_TRACE, __CLASS__);
				} else {
					$this->raven = $sentry->getRaven();
				}
			}
		}

		return $this->raven;
	}
}

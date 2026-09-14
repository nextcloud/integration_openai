<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Migration;

use Closure;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\ServiceConfig;
use OCA\OpenAi\Service\ServicesService;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Throwable;

/**
 * Migrates the single-service configuration to the list of connected services.
 *
 * The main configuration becomes the first service, and each of the per
 * modality URL overrides that was in use becomes a service of its own. The
 * models that were configured as defaults become the selected models of the
 * modality they belong to, and the feature toggles become the modality
 * switches of the service that modality was served by.
 */
class Version060000Date20260908120000 extends SimpleMigrationStep {
	/** Old config keys that are replaced by the service list */
	private const OBSOLETE_CONFIG_KEYS = [
		'url', 'service_name', 'api_key', 'basic_user', 'basic_password', 'use_basic_auth',
		'request_timeout', 'chat_endpoint_enabled', 'use_max_completion_tokens_param',
		'llm_extra_params', 'max_tokens', 'chunk_size', 'tts_voices', 'default_tts_voice',
		'default_speech_voice', 'default_speech_model_id', 'default_completion_model_id',
		'default_stt_model_id', 'default_image_model_id', 'default_image_size',
		'image_request_auth', 'quotas',
		'multimodal_image_enabled', 'multimodal_audio_enabled',
		'multimodal_video_enabled', 'multimodal_document_enabled',
		'llm_provider_enabled', 't2i_provider_enabled', 'stt_provider_enabled',
		'tts_provider_enabled', 'translation_provider_enabled',
		'image_url', 'image_service_name', 'image_api_key', 'image_basic_user',
		'image_basic_password', 'image_use_basic_auth', 'image_request_timeout',
		'stt_url', 'stt_service_name', 'stt_api_key', 'stt_basic_user',
		'stt_basic_password', 'stt_use_basic_auth', 'stt_request_timeout',
		'tts_url', 'tts_service_name', 'tts_api_key', 'tts_basic_user',
		'tts_basic_password', 'tts_use_basic_auth', 'tts_request_timeout',
		'models', 'models_image', 'models_stt', 'models_tts',
		'openai_text_generation_time', 'localai_text_generation_time',
		'openai_image_generation_time', 'localai_image_generation_time',
	];

	/** Where the server stores the preferred provider of each task type */
	private const PROVIDER_PREFERENCES_KEY = 'ai.taskprocessing_provider_preferences';

	/**
	 * The task processing providers of the old configuration, by the modality
	 * whose service and model they were served by.
	 *
	 * There was one provider per task type, so their IDs carried nothing but
	 * the task they performed. The new IDs name the service and the model as
	 * well, but end in the very same task slug, which makes the rewrite below
	 * a rename.
	 *
	 * The two providers that chain a second modality on top of their own are
	 * listed under the modality of the ID they were derived from: the
	 * audio-in/audio-out chat was built on the text provider, the audio
	 * translation on the transcription one.
	 */
	private const OLD_PROVIDER_SLUGS = [
		Application::MODALITY_TEXT => [
			'text2text', 'text2text:chat', 'text2text:chatwithtools',
			'text2text:multimodal-chatwithtools', 'text2text:summary',
			'text2text:headline', 'text2text:topics', 'text2text:emoji',
			'text2text:proofread', 'text2text:reformatparagraphs',
			'contextwrite', 'reformulate', 'improve', 'changetone', 'translate',
			'image2text-ocr', 'analyze-images', 'audio2audio:chat',
		],
		Application::MODALITY_IMAGE => [
			'text2image', 'text2image-improved-prompt',
		],
		Application::MODALITY_STT => [
			'audio2text', 'audio2text-subtitles', 'audio2text-enhanced',
			'audio2audio:translate',
		],
		Application::MODALITY_TTS => [
			'text2speech',
		],
	];

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IDBConnection $db,
		private IJobList $jobList,
		private ServicesService $servicesService,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('openai_quota_usage')) {
			return null;
		}
		$table = $schema->getTable('openai_quota_usage');
		if ($table->hasColumn('service_id')) {
			return null;
		}
		$table->addColumn('service_id', Types::STRING, [
			'notnull' => false,
			'length' => 64,
			'default' => '',
		]);
		$table->addIndex(['service_id'], 'oai_quota_service');
		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// The model lists are fetched on demand now, so the job that used to
		// warm their cache is gone. This is outside the guard below because it
		// also has to clean up after an upgrade that stopped halfway.
		$this->jobList->remove('OCA\\OpenAi\\Cron\\RefreshModels');

		if ($this->appConfig->getValueString(Application::APP_ID, Application::SERVICES_CONFIG_KEY, '', lazy: true) !== '') {
			// already migrated
			return;
		}

		// The service list is the marker this migration is guarded by, so it is
		// written only once every other step has succeeded. Until then nothing
		// observable has changed and an aborted upgrade can simply be re-run.
		$mainService = $this->buildMainService('s1');
		$services = [$mainService];
		/** @var array<int, string> $serviceIdByQuotaType */
		$serviceIdByQuotaType = [
			Application::QUOTA_TYPE_TEXT => $mainService->getId(),
			Application::QUOTA_TYPE_IMAGE => $mainService->getId(),
			Application::QUOTA_TYPE_TRANSCRIPTION => $mainService->getId(),
			Application::QUOTA_TYPE_SPEECH => $mainService->getId(),
		];
		/** @var array<string, ServiceConfig> $serviceByModality */
		$serviceByModality = [
			Application::MODALITY_TEXT => $mainService,
			Application::MODALITY_IMAGE => $mainService,
			Application::MODALITY_STT => $mainService,
			Application::MODALITY_TTS => $mainService,
		];

		foreach ($this->getOverrides() as $prefix => [$modality, $quotaType]) {
			$service = $this->buildOverrideService($prefix, $modality, 's' . (count($services) + 1));
			if ($service === null) {
				continue;
			}
			$services[] = $service;
			$serviceIdByQuotaType[$quotaType] = $service->getId();
			$serviceByModality[$modality] = $service;
		}

		// All three of these are re-runnable: the credential migration moves one
		// preference at a time, the usage attribution only touches rows that
		// have no service yet, and the provider preferences are rewritten from
		// IDs that the new configuration cannot produce again.
		$this->migrateUserCredentials($mainService->getId());
		$this->attributeQuotaUsage($serviceIdByQuotaType);
		$this->migrateProviderPreferences($serviceByModality, $output);

		// The ID generator is brought in sync with the IDs handed out above
		// before the service list is written, so that an upgrade aborting
		// between the two cannot hand out an ID that is already in use.
		$this->appConfig->setValueInt(Application::APP_ID, 'service_id_counter', count($services));
		$this->servicesService->setServices($services);

		$output->info('Migrated the OpenAI/LocalAI configuration to ' . count($services) . ' service(s)');

		foreach (self::OBSOLETE_CONFIG_KEYS as $key) {
			$this->appConfig->deleteKey(Application::APP_ID, $key);
		}
	}

	/**
	 * The modality overrides of the old configuration, and the quota type of
	 * the usage they accounted for
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	private function getOverrides(): array {
		return [
			'image_' => [Application::MODALITY_IMAGE, Application::QUOTA_TYPE_IMAGE],
			'stt_' => [Application::MODALITY_STT, Application::QUOTA_TYPE_TRANSCRIPTION],
			'tts_' => [Application::MODALITY_TTS, Application::QUOTA_TYPE_SPEECH],
		];
	}

	/**
	 * The main configuration becomes the first service. It serves every
	 * modality that was enabled and not overridden by its own URL.
	 */
	private function buildMainService(string $id): ServiceConfig {
		$values = [
			'name' => $this->getString('service_name'),
			'url' => $this->getString('url'),
			'api_key' => $this->getString('api_key'),
			'basic_user' => $this->getString('basic_user'),
			'basic_password' => $this->getString('basic_password'),
			'use_basic_auth' => $this->getBool('use_basic_auth', false),
			'request_timeout' => $this->getInt('request_timeout', Application::OPENAI_DEFAULT_REQUEST_TIMEOUT),
			'chat_endpoint_enabled' => $this->getBool('chat_endpoint_enabled', true),
			'use_max_completion_tokens_param' => $this->getNullableBool('use_max_completion_tokens_param'),
			'llm_extra_params' => $this->getString('llm_extra_params'),
			'max_tokens' => $this->getInt('max_tokens', Application::DEFAULT_MAX_NUM_OF_TOKENS),
			'chunk_size' => $this->getInt('chunk_size', Application::DEFAULT_CHUNK_SIZE),
			'multimodal_image_enabled' => $this->getBool('multimodal_image_enabled', true),
			// these defaults are the ones of the old configuration, so that a
			// service keeps accepting what it accepted before the upgrade
			'multimodal_audio_enabled' => $this->getBool('multimodal_audio_enabled', true),
			'multimodal_video_enabled' => $this->getBool('multimodal_video_enabled', false),
			'multimodal_document_enabled' => $this->getBool('multimodal_document_enabled', true),
			'tts_voices' => $this->getArray('tts_voices', Application::DEFAULT_SPEECH_VOICES),
			'default_tts_voice' => $this->getString('default_speech_voice') ?: Application::DEFAULT_SPEECH_VOICE,
			'default_image_size' => $this->getString('default_image_size') ?: Application::DEFAULT_DEFAULT_IMAGE_SIZE,
			'image_request_auth' => $this->getNullableBool('image_request_auth'),
			'quotas' => $this->getQuotas(),
			// The models that were configured as defaults are the ones to expose
			'text_enabled' => $this->getBool('llm_provider_enabled', true),
			'translation_enabled' => $this->getBool('translation_provider_enabled', true),
			'text_models' => [$this->getString('default_completion_model_id') ?: Application::DEFAULT_COMPLETION_MODEL_ID],
		];

		// a modality with its own URL is migrated to a service of its own below
		foreach ($this->getOverrides() as $prefix => [$modality, $_]) {
			$overridden = $this->getString($prefix . 'url') !== '';
			$values[$modality . '_enabled'] = !$overridden && $this->isModalityEnabledInOldConfig($modality);
			$values[$modality . '_models'] = $overridden ? [] : [$this->getOldModel($modality)];
		}

		return ServiceConfig::fromArray($id, $values);
	}

	/**
	 * A modality that had its own URL becomes a service that serves only that
	 * modality
	 */
	private function buildOverrideService(string $prefix, string $modality, string $id): ?ServiceConfig {
		$url = $this->getString($prefix . 'url');
		if ($url === '') {
			return null;
		}
		$values = [
			'name' => $this->getString($prefix . 'service_name'),
			'url' => $url,
			'api_key' => $this->getString($prefix . 'api_key'),
			'basic_user' => $this->getString($prefix . 'basic_user'),
			'basic_password' => $this->getString($prefix . 'basic_password'),
			'use_basic_auth' => $this->getBool($prefix . 'use_basic_auth', false),
			'request_timeout' => $this->getInt($prefix . 'request_timeout', Application::OPENAI_DEFAULT_REQUEST_TIMEOUT),
			'tts_voices' => $this->getArray('tts_voices', Application::DEFAULT_SPEECH_VOICES),
			'default_tts_voice' => $this->getString('default_speech_voice') ?: Application::DEFAULT_SPEECH_VOICE,
			'default_image_size' => $this->getString('default_image_size') ?: Application::DEFAULT_DEFAULT_IMAGE_SIZE,
			'image_request_auth' => $this->getNullableBool('image_request_auth'),
			'quotas' => $this->getQuotas(),
			'text_enabled' => false,
			'image_enabled' => false,
			'stt_enabled' => false,
			'tts_enabled' => false,
			'translation_enabled' => $this->getBool('translation_provider_enabled', true),
		];
		$values[$modality . '_enabled'] = $this->isModalityEnabledInOldConfig($modality);
		$values[$modality . '_models'] = [$this->getOldModel($modality)];

		return ServiceConfig::fromArray($id, $values);
	}

	private function isModalityEnabledInOldConfig(string $modality): bool {
		return match ($modality) {
			Application::MODALITY_IMAGE => $this->getBool('t2i_provider_enabled', true),
			Application::MODALITY_STT => $this->getBool('stt_provider_enabled', true),
			Application::MODALITY_TTS => $this->getBool('tts_provider_enabled', true),
			default => $this->getBool('llm_provider_enabled', true),
		};
	}

	/**
	 * The model that was configured as the default for one of the overridable
	 * modalities. The "Default" pseudo model means the service serves one
	 * fixed model, which is what the old configuration fell back to for these.
	 */
	private function getOldModel(string $modality): string {
		$key = match ($modality) {
			Application::MODALITY_IMAGE => 'default_image_model_id',
			Application::MODALITY_STT => 'default_stt_model_id',
			Application::MODALITY_TTS => 'default_speech_model_id',
		};
		return $this->getString($key) ?: Application::DEFAULT_MODEL_ID;
	}

	/**
	 * The credentials users provided for the old single service now belong to
	 * the service the main configuration was migrated to
	 */
	private function migrateUserCredentials(string $serviceId): void {
		$keys = ['api_key', 'basic_user', 'basic_password'];
		$qb = $this->db->getQueryBuilder();
		$qb->select('userid', 'configkey', 'configvalue')
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->in('configkey', $qb->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			if (!is_string($row['configvalue']) || $row['configvalue'] === '') {
				continue;
			}
			// the values are encrypted the same way as the new ones, so they can
			// be moved over as they are
			$this->config->setUserValue(
				(string)$row['userid'],
				Application::APP_ID,
				'service_' . $serviceId . '_' . $row['configkey'],
				$row['configvalue'],
			);
			$this->config->deleteUserValue((string)$row['userid'], Application::APP_ID, (string)$row['configkey']);
		}
		$result->closeCursor();
	}

	/**
	 * The providers this app registers are now one per service and model, so
	 * all of their IDs changed. Rewrite the ones the admin picked in the AI
	 * admin settings, so that a task type stays on this app instead of
	 * silently falling back to whichever provider the server finds first.
	 *
	 * Preferences naming a provider of another app are left untouched, and so
	 * is one naming a modality that the old configuration had switched off:
	 * that one did not resolve before the upgrade either.
	 *
	 * @param array<string, ServiceConfig> $serviceByModality
	 */
	private function migrateProviderPreferences(array $serviceByModality, IOutput $output): void {
		try {
			$stored = $this->appConfig->getValueString('core', self::PROVIDER_PREFERENCES_KEY, '', lazy: true);
			$preferences = $stored === '' ? null : json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
		} catch (Throwable) {
			// A list that cannot be read here cannot be read by the server
			// either, so it is already falling back to the first available
			// provider. Not worth failing the upgrade over.
			return;
		}
		if (!is_array($preferences)) {
			return;
		}

		/** @var array<string, string> $newIdByOldId */
		$newIdByOldId = [];
		foreach (self::OLD_PROVIDER_SLUGS as $modality => $slugs) {
			$service = $serviceByModality[$modality];
			$model = $service->getFirstModel($modality);
			if ($model === null) {
				continue;
			}
			$prefix = Application::APP_ID . '-' . $service->getId() . '-' . self::slugifyModel($model) . '-';
			foreach ($slugs as $slug) {
				$newIdByOldId[Application::APP_ID . '-' . $slug] = $prefix . $slug;
			}
		}

		$migrated = 0;
		foreach ($preferences as $taskTypeId => $providerId) {
			if (is_string($providerId) && isset($newIdByOldId[$providerId])) {
				$preferences[$taskTypeId] = $newIdByOldId[$providerId];
				$migrated++;
			}
		}
		if ($migrated === 0) {
			return;
		}

		$this->appConfig->setValueString(
			'core',
			self::PROVIDER_PREFERENCES_KEY,
			json_encode($preferences, JSON_THROW_ON_ERROR),
			lazy: true,
		);
		$output->info('Migrated ' . $migrated . ' preferred task processing provider(s) to the new provider IDs');
	}

	/**
	 * Model names can contain characters that don't belong in an ID.
	 *
	 * Kept in step with {@see \OCA\OpenAi\TaskProcessing\ProviderIdentity::slugifyModel()}
	 * rather than calling it, so that this migration keeps producing the IDs
	 * that were current when it ran, whatever the providers do later.
	 */
	private static function slugifyModel(string $model): string {
		return preg_replace('/[^A-Za-z0-9._-]/', '_', $model) ?? $model;
	}

	/**
	 * Existing usage rows have no service. Attribute them to the service the
	 * modality of their quota type was served by, so the quotas of the current
	 * period keep being enforced across the upgrade.
	 *
	 * @param array<int, string> $serviceIdByQuotaType
	 */
	private function attributeQuotaUsage(array $serviceIdByQuotaType): void {
		foreach ($serviceIdByQuotaType as $quotaType => $serviceId) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->update('openai_quota_usage')
					->set('service_id', $qb->createNamedParameter($serviceId, IQueryBuilder::PARAM_STR))
					->where($qb->expr()->eq('type', $qb->createNamedParameter($quotaType, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->orX(
						$qb->expr()->eq('service_id', $qb->createNamedParameter('', IQueryBuilder::PARAM_STR)),
						$qb->expr()->isNull('service_id'),
					));
				$qb->executeStatement();
			} catch (Throwable $e) {
				// usage attribution is not worth failing the upgrade for
				continue;
			}
		}
	}

	/**
	 * Read a value of the old configuration as a string.
	 *
	 * The old configuration wrote its values with different typed setters
	 * ('chunk_size' as an int, most of the others as strings), and IAppConfig
	 * throws when the getter does not match the type a value was stored with.
	 * The upgrade must not abort over that, so the stored type decides which
	 * getter is used.
	 */
	private function getString(string $key): string {
		try {
			$type = $this->appConfig->getValueType(Application::APP_ID, $key, lazy: true);
		} catch (AppConfigUnknownKeyException) {
			return '';
		}
		return match ($type) {
			IAppConfig::VALUE_INT => (string)$this->appConfig->getValueInt(Application::APP_ID, $key, lazy: true),
			IAppConfig::VALUE_FLOAT => (string)$this->appConfig->getValueFloat(Application::APP_ID, $key, lazy: true),
			IAppConfig::VALUE_BOOL => $this->appConfig->getValueBool(Application::APP_ID, $key, lazy: true) ? '1' : '0',
			IAppConfig::VALUE_ARRAY => json_encode(
				$this->appConfig->getValueArray(Application::APP_ID, $key, lazy: true),
				JSON_THROW_ON_ERROR,
			),
			default => $this->appConfig->getValueString(Application::APP_ID, $key, '', lazy: true),
		};
	}

	private function getInt(string $key, int $default): int {
		$value = $this->getString($key);
		return $value === '' ? $default : (int)$value;
	}

	private function getBool(string $key, bool $default): bool {
		$value = $this->getString($key);
		return $value === '' ? $default : $value === '1';
	}

	private function getNullableBool(string $key): ?bool {
		$value = $this->getString($key);
		return $value === '' ? null : $value === '1';
	}

	/**
	 * @param list<string> $default
	 * @return list<string>
	 */
	private function getArray(string $key, array $default): array {
		$value = $this->getString($key);
		if ($value === '') {
			return $default;
		}
		$decoded = json_decode($value, true);
		return is_array($decoded) && $decoded !== [] ? array_values($decoded) : $default;
	}

	/**
	 * @return array<int, int>
	 */
	private function getQuotas(): array {
		$quotas = json_decode($this->getString('quotas'), true);
		if (!is_array($quotas)) {
			return Application::DEFAULT_QUOTAS;
		}
		$result = Application::DEFAULT_QUOTAS;
		foreach (array_keys(Application::DEFAULT_QUOTAS) as $type) {
			if (isset($quotas[$type]) && is_numeric($quotas[$type])) {
				$result[$type] = max(0, (int)$quotas[$type]);
			}
		}
		return $result;
	}
}

<?php
/**
 * @copyright Copyright (c) 2022 Julien Veyssier <julien-nc@posteo.net>
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\OpenAi\Reference;

use OCA\OpenAi\Db\ImageGenerationMapper;
use OCA\OpenAi\Db\ImageUrlMapper;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OC\Collaboration\Reference\ReferenceManager;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Collaboration\Reference\IReference;
use OCP\IConfig;
use OCP\IL10N;

use OCP\IURLGenerator;

class ImageReferenceProvider extends ADiscoverableReferenceProvider  {

	private const RICH_OBJECT_TYPE = Application::APP_ID . '_image';

	private OpenAiAPIService $openAiAPIService;
	private ?string $userId;
	private IL10N $l10n;
	private IURLGenerator $urlGenerator;

	public function __construct(OpenAiAPIService $openAiAPIService,
								IL10N $l10n,
								IURLGenerator $urlGenerator,
								?string $userId) {
		$this->openAiAPIService = $openAiAPIService;
		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string	{
		return 'openai-image';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		return $this->l10n->t('AI image generation (by OpenAI Dall-E 2)');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int	{
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
		);
	}

	/**
	 * @inheritDoc
	 */
	public function matchReference(string $referenceText): bool {
		return $this->getImageGenerationHash($referenceText) !== null;
	}

	/**
	 * @inheritDoc
	 */
	public function resolveReference(string $referenceText): ?IReference {
		if ($this->matchReference($referenceText)) {
			$hash = $this->getImageGenerationHash($referenceText);
			if ($hash === null) {
				return null;
			}

			$reference = new Reference($referenceText);
			$richObjectInfo = $this->openAiAPIService->getGenerationInfo($hash);
			$reference->setRichObject(
				self::RICH_OBJECT_TYPE,
				$richObjectInfo,
			);
			return $reference;
		}

		return null;
	}

	/**
	 * @param string $url
	 * @return array|null
	 */
	private function getImageGenerationHash(string $url): ?string {
		$start = $this->urlGenerator->getAbsoluteURL('/apps/' . Application::APP_ID);
		$startIndex = $this->urlGenerator->getAbsoluteURL('/index.php/apps/' . Application::APP_ID);

		// link example: https://nextcloud.local/index.php/apps/integration_openai/i/3jf5wq3hibbqvickir7ysqehfi
		preg_match('/^' . preg_quote($start, '/') . '\/i\/([0-9a-z]+)$/i', $url, $matches);
		if (count($matches) > 1) {
			return $matches[1];
		}

		preg_match('/^' . preg_quote($startIndex, '/') . '\/i\/([0-9a-z]+)$/i', $url, $matches);
		if (count($matches) > 1) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function getCachePrefix(string $referenceId): string {
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function getCacheKey(string $referenceId): ?string {
		return $referenceId;
	}
}

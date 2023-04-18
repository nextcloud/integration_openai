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

use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OC\Collaboration\Reference\ReferenceManager;
use OCA\OpenAi\AppInfo\Application;
use OCP\Collaboration\Reference\IReference;
use OCP\IL10N;

use OCP\IURLGenerator;

class ChatGptReferenceProvider extends ADiscoverableReferenceProvider  {

	private const RICH_OBJECT_TYPE = Application::APP_ID . '_chatgpt_internal_link';

	public function __construct(private IL10N $l10n,
								private IURLGenerator $urlGenerator,
								private ReferenceManager $referenceManager,
								private ?string $userId) {
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string	{
		return 'openai-chatgpt';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		return $this->l10n->t('ChatGPT-like text generation (by OpenAI)');
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
		return false;
		/*
		$start = $this->urlGenerator->getAbsoluteURL('/apps/' . Application::APP_ID);
		$startIndex = $this->urlGenerator->getAbsoluteURL('/index.php/apps/' . Application::APP_ID);

		// link example: https://nextcloud.local/index.php/apps/integration_openai/c/3jf5wq3hibbqvickir7ysqehfi
		$noIndexMatch = preg_match('/^' . preg_quote($start, '/') . '\/c\/[0-9a-z]+$/', $referenceText) === 1;
		$indexMatch = preg_match('/^' . preg_quote($startIndex, '/') . '\/c\/[0-9a-z]+$/', $referenceText) === 1;
		return $noIndexMatch || $indexMatch;
		*/
	}

	/**
	 * @inheritDoc
	 */
	public function resolveReference(string $referenceText): ?IReference {
		if ($this->matchReference($referenceText)) {
			$completionId = $this->getCompletionId($referenceText);
			$reference = new Reference($referenceText);
			$richObjectInfo = [
				'completionId' => $completionId,
			];

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
	private function getCompletionId(string $url): ?string {
		preg_match('/\/c\/([0-9a-z]+)$/i', $url, $matches);
		if (count($matches) > 1) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * We use the userId here because when connecting/disconnecting from the GitHub account,
	 * we want to invalidate all the user cache and this is only possible with the cache prefix
	 * @inheritDoc
	 */
	public function getCachePrefix(string $referenceId): string {
		return $this->userId ?? '';
	}

	/**
	 * We don't use the userId here but rather a reference unique id
	 * @inheritDoc
	 */
	public function getCacheKey(string $referenceId): ?string {
		$predictionId = $this->getCompletionId($referenceId);
		if ($predictionId !== null) {
			return $predictionId;
		}

		return $referenceId;
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function invalidateUserCache(string $userId): void {
		$this->referenceManager->invalidateCache($userId);
	}
}

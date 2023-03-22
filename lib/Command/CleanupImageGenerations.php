<?php

/**
 * Nextcloud - OpenAI
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2023
 */

namespace OCA\OpenAi\Command;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\ImageGenerationMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupImageGenerations extends Command {

	private ImageGenerationMapper $imageGenerationMapper;

	public function __construct(ImageGenerationMapper $imageGenerationMapper) {
		parent::__construct();
		$this->imageGenerationMapper = $imageGenerationMapper;
	}

	protected function configure() {
		$this->setName('openai:cleanup')
			->setDescription('Cleanup image generation data')
			->addArgument(
				'max_age',
				InputArgument::OPTIONAL,
				'The max idle time (in seconds)',
				Application::MAX_GENERATION_IDLE_TIME
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$maxAge = $input->getArgument('max_age');
		$cleanedUp = $this->imageGenerationMapper->cleanupGenerations($maxAge);
		$output->writeln('Deleted ' . $cleanedUp . ' idle generations');
		return 0;
	}
}

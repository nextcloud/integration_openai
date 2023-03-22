<!--
  - @copyright Copyright (c) 2023 Julien Veyssier <julien-nc@posteo.net>
  -
  - @author 2023 Julien Veyssier <julien-nc@posteo.net>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -->

<template>
	<NcContent app-name="integration_openai">
		<NcAppContent>
			<ImageGeneration :hash="generation.hash"
				:urls="generation.urls"
				:prompt="generation.prompt" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import ImageGeneration from '../components/ImageGeneration.vue'

import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'

import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'ImageGenerationPage',

	components: {
		ImageGeneration,
		NcContent,
		NcAppContent,
	},

	props: {
	},

	data() {
		return {
			generation: loadState('integration_openai', 'generation'),
		}
	},

	computed: {
	},

	mounted() {
	},

	methods: {
	},
}
</script>

<style scoped lang="scss">
::v-deep .generation {
	display: flex;
	flex-direction: column;
	//height: 100%;
	align-items: center;
	justify-content: center;
}
</style>

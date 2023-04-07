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
	<div>
		<div v-if="isImageLoading" class="loading-icon">
			<NcLoadingIcon
				:size="44"
				:title="t('integration_openai', 'Loading image')" />
		</div>
		<img v-show="!isImageLoading && !failed"
			class="image"
			:src="src"
			:aria-label="t('integration_openai', 'Generated image')"
			@load="isImageLoading = false"
			@error="onError">
		<span v-if="failed">
			{{ t('integration_openai', 'The remote image cannot be fetched. OpenAI might have deleted it.') }}
			<a :href="directLink" target="_blank" class="external">
				{{ t('integration_openai', 'Direct image link') }}
			</a>
		</span>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'OpenAiImage',

	components: {
		NcLoadingIcon,
	},

	props: {
		src: {
			type: String,
			required: true,
		},
		directLink: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			isImageLoading: true,
			failed: false,
		}
	},

	computed: {
	},

	mounted() {
	},

	methods: {
		onError(e) {
			this.isImageLoading = false
			this.failed = true
		},
	},
}
</script>

<style scoped lang="scss">
.image {
	max-height: 300px;
	max-width: 100%;
	border-radius: var(--border-radius-large);
}
</style>

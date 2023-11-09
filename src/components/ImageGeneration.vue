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
	<div class="generation">
		<span class="title">
			<OpenAiIcon :size="20" class="icon" />
			<strong>
				{{ t('integration_openai', 'Image generation') + ':' }}
			</strong>
			&nbsp;
			<span v-if="error">
				{{ formattedError }}
			</span>
			<span v-else>
				{{ prompt }}
			</span>
		</span>
		<div v-if="!error"
			:class="{ images: true, vertical: orientation === 'vertical' }">
			<a v-for="url in urls"
				:key="url.id"
				:href="url.url"
				:aria-label="t('integration_openai', 'Open image in a new tab')"
				:title="t('integration_openai', 'Open image in a new tab')"
				target="_blank"
				class="image-wrapper">
				<OpenAiImage :src="getProxiedImageUrl(url.id)"
					:direct-link="url.url" />
			</a>
		</div>
	</div>
</template>

<script>
import OpenAiIcon from './icons/OpenAiIcon.vue'
import OpenAiImage from './OpenAiImage.vue'

import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ImageGeneration',

	components: {
		OpenAiImage,
		OpenAiIcon,
	},

	props: {
		hash: {
			type: String,
			default: null,
		},
		urls: {
			type: Array,
			default: () => [],
		},
		prompt: {
			type: String,
			default: null,
		},
		error: {
			type: String,
			default: null,
		},
		orientation: {
			type: String,
			default: 'vertical',
			validator: val => ['horizontal', 'vertical'].includes(val),
		},
	},

	data() {
		return {
			isImageLoaded: {},
		}
	},

	computed: {
		formattedError() {
			if (this.error === 'notfound') {
				return t('integration_openai', 'Image information was not found on the server. The data might have been cleaned up because the image has not been displayed for a long time.')
			}
			return '??'
		},
	},

	mounted() {
	},

	methods: {
		getProxiedImageUrl(urlId) {
			return generateUrl(
				'/apps/integration_openai/images/generations/{hash}/{urlId}',
				{ hash: this.hash, urlId }
			)
		},
	},
}
</script>

<style scoped lang="scss">
.generation {
	width: 100%;
	padding: 12px;
	white-space: normal;

	.title {
		margin-top: 0;
		.icon {
			display: inline;
			position: relative;
			top: 4px;
		}
	}

	.images {
		margin-top: 12px;
		display: flex;

		.image-wrapper {
			width: 100%;
			display: flex;
			align-items: center;
			justify-content: center;
			position: relative;
			margin: 8px 4px 0 4px;
		}

		&.vertical {
			flex-direction: column;
			.image-wrapper {
				margin-left: 0px;
				margin-right: 0px;
			}
		}
	}
}
</style>

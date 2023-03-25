<template>
	<div class="openai-picker-content">
		<h2>
			{{ t('integration_openai', 'AI speech-to-text') }}
		</h2>
		<a class="attribution"
			target="_blank"
			href="https://openai.com">
			{{ poweredByTitle }}
		</a>
		<audio-recorder
			class="recorder"
			:attempts="1"
			:time="120"
			:show-download-button="true"
			:show-upload-button="false"
			:after-recording="onRecordEnd" />
		<div class="form-wrapper">
			<div class="line">
				<label>
					{{ t('integration_openai', 'Action') }}
				</label>
				<div class="spacer" />
				<div class="radios">
					<NcCheckboxRadioSwitch
						:checked.sync="mode"
						type="radio"
						value="transcribe"
						name="mode">
						{{ t('integration_openai', 'Transcribe') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked.sync="mode"
						type="radio"
						value="translate"
						name="mode">
						{{ t('integration_openai', 'Translate (only to English)') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</div>
		<div class="footer">
			<span v-if="error" class="error">
				{{ error }}
			</span>
			<NcButton
				type="primary"
				:disabled="loading || audio === null"
				@click="onInputEnter">
				<template #icon>
					<NcLoadingIcon v-if="loading"
						:size="20" />
					<ArrowRightIcon v-else />
				</template>
				{{ t('integration_openai', 'Submit') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import VueAudioRecorder from 'vue2-audio-recorder'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

import Vue from 'vue'
Vue.use(VueAudioRecorder)

export default {
	name: 'WhisperCustomPickerElement',

	components: {
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		ArrowRightIcon,
	},

	props: {
		providerId: {
			type: String,
			required: true,
		},
		accessible: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			loading: false,
			poweredByTitle: t('integration_openai', 'by OpenAI with Whisper'),
			mode: 'transcribe',
			audio: null,
			error: null,
		}
	},

	computed: {
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		async onRecordEnd(e) {
			const readBlob = (blob) => {
				const reader = new FileReader()
				return new Promise((resolve) => {
					reader.addEventListener('load', () => {
						resolve(reader.result)
					})
					reader.readAsDataURL(blob)
				})
			}

			try {
				this.audio = await readBlob(e.blob)
			} catch (e) {
				console.error('recording error', e.message)
			}
		},
		onSubmit(url) {
			this.$emit('submit', url)
		},
		onInputEnter() {
			this.loading = true
			this.error = null
			const params = {
				translate: this.mode === 'translate',
				audioBase64: this.audio,
			}
			const url = generateUrl('/apps/integration_openai/audio/transcriptions')
			return axios.post(url, params)
				.then((response) => {
					console.debug('whisper response', response.data)
					this.onSubmit(response.data.text)
				})
				.catch((error) => {
					console.debug('openai whisper request error', error)
					showError(
						t('integration_openai', 'Failed to get transcription/translation')
						+ ': ' + (error.response?.data?.body?.detail ?? error.response?.data?.error)
					)
				})
				.then(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style scoped lang="scss">
.openai-picker-content {
	width: 100%;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;

	h2 {
		display: flex;
		align-items: center;
	}

	.spacer {
		flex-grow: 1;
	}

	.attribution {
		padding-bottom: 12px;
	}

	.form-wrapper {
		display: flex;
		flex-direction: column;
		align-items: center;
		width: 100%;
		margin: 8px 0;
		.radios {
			display: flex;
			> * {
				margin: 0 16px;
			}
		}
	}

	.line {
		display: flex;
		align-items: center;
		margin-top: 8px;
		width: 100%;
	}

	.footer {
		display: flex;
		align-items: center;
		justify-content: end;
		width: 100%;

		.error {
			margin-right: 12px;
		}
	}

	::v-deep .recorder {
		.ar-recorder__time-limit {
			position: unset !important;
		}
		.ar-player__time {
			font-size: 14px;
		}
		.ar-records {
			height: unset !important;
		}
	}
}
</style>

# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## 1.0.11 – 2023-07-12

### Added

- Ethical AI rating [#24](https://github.com/nextcloud/integration_openai/issues/24) [#29](https://github.com/nextcloud/integration_openai/issues/29) @marcelklehr

## 1.0.10 – 2023-06-06

### Changed

- do not use OC functions/vars anymore @julien-nc

### Fixed

- App was not working if no admin api key is defined [#16](https://github.com/nextcloud/integration_openai/issues/16) @julien-nc

## 1.0.9 – 2023-05-31

### Added

- Allow using a custom service URL [#15](https://github.com/nextcloud/integration_openai/issues/15) [#18](https://github.com/nextcloud/integration_openai/issues/18) @julien-nc
- New admin settings to toggle each feature [#15](https://github.com/nextcloud/integration_openai/issues/15) @julien-nc

### Changed

- Bump max Nextcloud version to 28 @julien-nc
- Immediately update prompt history in chat/image picker components @julien-nc

## 1.0.8 – 2023-05-17

### Added

- translation provider @julien-nc
- allow user-specific API keys [#16](https://github.com/nextcloud/integration_openai/issues/16) @julien-nc

### Changed

- improve style of audio recorder @julien-nc
- use latest nextcloud/vue so the picker modal size can be set by the provider

## 1.0.7 – 2023-05-11

### Changed

- change regenerate icon @julien-nc
- disable inputs during generation/regeneration @julien-nc
- set prompt history bubble max width, add title with full text @julien-nc

## 1.0.6 – 2023-05-09

### Added

- implement OCP\SpeechToText\ISpeechToTextProvider @julien-nc

### Changed

- improve style of the whisper picker component, respect dark theme @julien-nc
- improve style of the GPT and Dall-e picker components @julien-nc
- remember last image size for Dall-e picker @julien-nc
- implement 2 step flow in GPT and Dall-e picker components, preview/adjust before submitting @julien-nc

## 1.0.5 – 2023-04-26

### Added

- add prompt history for text and image generation @julien-nc

### Changed

- add padding in the picker component because the one in the picker will be removed @julien-nc
- get rid of `@nextcloud/vue-richtext` dependency as the build bug has been solved in the nextcloud webpack config @julien-nc

## 1.0.4 – 2023-04-07
### Added
- gpt picker option to set max number of tokens (~words) to generate

## 1.0.3 – 2023-04-07
### Added
- 'include query' option in gpt custom picker
- link preview for Dall-e image generations
- support for gpt-3.5
- admin setting to choose default completion model
- save last used model as default user-specific default completion model
- Whisper speech-to-text smart picker provider to translate/transcode

### Fixed
- show error message of failing API request responses

## 1.0.2 – 2023-03-03
### Changed
- improve design
- use more NC components

## 1.0.0 – 2022-12-19
### Added
* the app

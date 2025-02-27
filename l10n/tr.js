OC.L10N.register(
    "integration_openai",
    {
    "Unknown models error" : "Bilinmeyen modeller sorunu",
    "Default" : "Varsayılan",
    "Text generation" : "Metin oluşturma",
    "Image generation" : "Görsel oluşturma",
    "Audio transcription" : "Sesten yazıya dönüştürme",
    "Unknown" : "Bilinmiyor",
    "tokens" : "kodlar",
    "images" : "görseller",
    "seconds" : "saniye",
    "Unknown error while retrieving quota usage." : "Kota kullanımı alınırken bilinmeyen bir sorun çıktı",
    "Text generation quota exceeded" : "Metin oluşturma kotası aşıldı",
    "Unknown text generation error" : "Bilinmeyen metin oluşturma sorunu",
    "Could not read audio file." : "Ses dosyası okunamadı.",
    "Audio transcription quota exceeded" : "Sesten yazıya dönüştürme kotası aşıldı",
    "Unknown audio trancription error" : "Bilinmeyen sesten yazıya dönüştürme sorunu",
    "Image generation quota exceeded" : "Görsel oluşturma kotası aşıldı",
    "Unknown image generation error" : "Bilinmeyen görsel oluşturma sorunu",
    "Bad HTTP method" : "HTTP yöntemi hatalı",
    "Bad credentials" : "Kimlik doğrulama bilgileri hatalı",
    "API request error: " : "API isteği sorunu:",
    "Detect language" : "Dili algıla",
    "Friendlier" : "Arkadaşça",
    "More formal" : "Daha resmi",
    "Funnier" : "Daha eğlenceli",
    "More casual" : "Daha günlük",
    "More urgent" : "Daha acil",
    "Maximum output words" : "En fazla çıktı sözcüğü sayısı",
    "The maximum number of words/tokens that can be generated in the completion." : "Tamamlarken üretilebilecek en fazla sözcük/kod sayısı.",
    "Model" : "Model",
    "The model used to generate the completion" : "Tamamlarken kullanılacak model",
    "Change Tone" : "Tonu değiştir",
    "Ask a question about your data." : "Verileriniz hakkında bir soru sorun.",
    "Input text" : "Giriş metni",
    "Write a text that you want the assistant to rewrite in another tone." : "Yardımcının başka bir tonda yeniden yazmasını istediğiniz bir metin yazın",
    "Desired tone" : "İstenilen ton",
    "In which tone should your text be rewritten?" : "Metin hangi tonda yeniden yazılmalı?",
    "Generated response" : "Üretilen yanıt",
    "The rewritten text in the desired tone, written by the assistant:" : "Yardımcı tarafından istenilen tonda yeniden yazılan metin:",
    "OpenAI's DALL-E 2" : "OpenAI DALL-E 2",
    "Size" : "Boyut",
    "Optional. The size of the generated images. Must be in 256x256 format. Default is %s" : "İsteğe bağlı. Oluşturulan görsellerin boyutu. 256x256 boyutunda olmalıdır. Varsayılan değer: %s",
    "The model used to generate the images" : "Görselleri oluşturmakta kullanılacak model",
    "OpenAI and LocalAI integration" : "OpenAI ve LocalAI bütünleştirmesi",
    "Integration of OpenAI and LocalAI services" : "OpenAI ve LocalAI hizmetleri bütünleştirmesi",
    "⚠️ The smart pickers have been removed from this app\nas they are now included in the [Assistant app](https://apps.nextcloud.com/apps/assistant).\n\nThis app implements:\n\n* Text generation providers: Free prompt, Summarize, Headline, Context Write, Chat, and Reformulate (using any available large language model)\n* A Translation provider (using any available language model)\n* A SpeechToText provider (using Whisper)\n* An image generation provider\n\n⚠️ Context Write, Summarize, Headline and Reformulate have mainly been tested with OpenAI.\nThey might work when connecting to other services, without any guarantee.\n\nInstead of connecting to the OpenAI API for these, you can also connect to a self-hosted [LocalAI](https://localai.io) instance or [Ollama](https://ollama.com/) instance\nor to any service that implements an API similar to the OpenAI one, for example:\n[IONOS AI Model Hub](https://docs.ionos.com/cloud/ai/ai-model-hub), [Plusserver](https://www.plusserver.com/en/ai-platform/) or [MistralAI](https://mistral.ai).\n\n⚠️ This app is mainly tested with OpenAI. We do not guarantee it works perfectly\nwith other services that implement OpenAI-compatible APIs with slight differences.\n\n## Improve AI task pickup speed\n\nTo avoid task processing execution delay, setup at 4 background job workers in the main server (where Nextcloud is installed). The setup process is documented here: https://docs.nextcloud.com/server/latest/admin_manual/ai/overview.html#improve-ai-task-pickup-speed\n\n## Ethical AI Rating\n### Rating for Text generation using ChatGPT via the OpenAI API: 🔴\n\nNegative:\n* The software for training and inference of this model is proprietary, limiting running it locally or training by yourself\n* The trained model is not freely available, so the model can not be run on-premises\n* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model's performance and CO2 usage.\n\n\n### Rating for Translation using ChatGPT via the OpenAI API: 🔴\n\nNegative:\n* The software for training and inference of this model is proprietary, limiting running it locally or training by yourself\n* The trained model is not freely available, so the model can not be run on-premises\n* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model's performance and CO2 usage.\n\n### Rating for Image generation using DALL·E via the OpenAI API: 🔴\n\nNegative:\n* The software for training and inferencing of this model is proprietary, limiting running it locally or training by yourself\n* The trained model is not freely available, so the model can not be ran on-premises\n* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.\n\n\n### Rating for Speech-To-Text using Whisper via the OpenAI API: 🟡\n\nPositive:\n* The software for training and inferencing of this model is open source\n* The trained model is freely available, and thus can run on-premise\n\nNegative:\n* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.\n\n### Rating for Text generation via LocalAI: 🟢\n\nPositive:\n* The software for training and inferencing of this model is open source\n* The trained model is freely available, and thus can be ran on-premises\n* The training data is freely available, making it possible to check or correct for bias or optimise the performance and CO2 usage.\n\n\n### Rating for Image generation using Stable Diffusion via LocalAI : 🟡\n\nPositive:\n* The software for training and inferencing of this model is open source\n* The trained model is freely available, and thus can be ran on-premises\n\nNegative:\n* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.\n\n\n### Rating for Speech-To-Text using Whisper via LocalAI: 🟡\n\nPositive:\n* The software for training and inferencing of this model is open source\n* The trained model is freely available, and thus can be ran on-premises\n\nNegative:\n* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.\n\nLearn more about the Nextcloud Ethical AI Rating [in our blog](https://nextcloud.com/blog/nextcloud-ethical-ai-rating/)." : "⚠️ Akıllı seçiciler artık [Yardımcı uygulamasına](https://apps.nextcloud.com/apps/assistant)\ntaşındığından bu uygulamadan kaldırıldı.\n\nBu uygulamada şu özellikler bulunur:\n\n* Metin oluşturma hizmeti sağlayıcıları: Serbest istem, özetleme, bağlam yazma, başlık ve yeniden biçimlendirme (var olan herhangi bir büyük dil modelini kullanarak)\n* Bir çeviri hizmeti sağlayıcısı (kullanılabilecek herhangi bir dil modeli ile)\n* Bir konuşmadan yazıya çevirici (Whisper ile)\n* Bir görsel oluşturma hizmeti sağlayıcısı\n\n⚠️ Bağlam yazma, Özetleme, Başlık çıkarma ve yeniden biçimlendirme çoğunlukla OpenAI ile denenmiştir.\nDiğer hizmetlere bağlanırken herhangi bir garanti olmaksızın çalışabilirler.\n\nBunlar için OpenAI API uygulamasına bağlanmak yerine, kendinizin barındıracağı bir [LocalAI](https://localai.io) kopyasına ya da [Ollama](https://ollama.com/) kopyasına ya da\nOpenAI benzeri bir API sağlayan herhangi bir hizmete de bağlanabilirsiniz. Örneğin [IONOS AI Model Hub](https://docs.ionos.com/cloud/ai/ai-model-hub), [Plusserver](https://www.plusserver.com/en/ai-platform/) veya [MistralAI](https://mistral.ai)\n\n⚠️ Bu uygulama temel olarak OpenAI ile denenmiştir. OpenAI ile uyumlu API uygulamalarını ufak farklarla kullanan diğer hizmetlerle kusursuz çalışacağını garanti etmiyoruz.\n\n## Yapay zekanın görev alma hızını iyileştirin\n\nGörev işleme yürütme gecikmesini önlemek için, ana sunucuda (Nextcloud kurulu olan yer) 4 arka plan görevi kurun. Kurulum sürecini şurada bulabilirsiniz: https://docs.nextcloud.com/server/latest/admin_manual/ai/overview.html#improve-ai-task-pickup-speed\n\n## Etik AI Değerlendirmesi\n### OpenAI API ile ChatGPT kullanarak metin oluşturma değerlendirmesi: 🔴\n\nOlumsuz:\n* Bu modelin eğitimi ve etkileşimi için kullanılan yazılım tescillidir. Yerel olarak çalıştırmayı veya kendi başınıza eğitimi sınırlandırır\n* Eğitimli model ücretsiz olarak kullanılamaz. Bu nedenle model şirket içinde çalıştırılamaz\n* Eğitim verileri ücretsiz olarak kullanılamaz. Bu da dış tarafların önyargıyı kontrol etme ve düzeltme veya modelin başarımı ile CO2 kullanımını iyileştirme yeteneğini sınırlar.\n\n\n### OpenAI API ile ChatGPT kullanarak çeviri değerlendirmesi: 🔴\n\nOlumsuz:\n* Bu modelin eğitimi ve etkileişimi için kullanılan yazılım tescillidir. Yerel olarak çalıştırmayı veya kendi başınıza eğitimi sınırlandırır\n* Eğitimli model ücretsiz olarak kullanılamaz. Bu nedenle model şirket içinde çalıştırılamaz\n* Eğitim verileri ücretsiz olarak kullanılamaz. Bu da dış tarafların önyargıyı kontrol etme ve düzeltme veya modelin başarımı ile CO2 kullanımını iyileştirme yeteneğini sınırlar.\n\n### OpenAI API ile DALL·E kullanarak görsel oluşturma değerlendirmesi: 🔴\n\nOlumsuz:\n* Bu modelin eğitimi ve etkileşimi için kullanılan yazılım tescillidir. Yerel olarak çalıştırmayı veya kendi başınıza eğitimi sınırlandırır\n* Eğitilmiş model ücretsiz olarak kullanılamaz. Bu nedenle model şirket içinde çalıştırılamaz\n* Eğitim verileri ücretsiz olarak kullanılamaz. Bu da dış tarafların önyargıyı kontrol etme ve düzeltme veya modelin başarımı ile CO2 kullanımını iyileştirme yeteneğini sınırlar.\n\n\n### OpenAI API ile Whisper kullanarak konuşmadan yazıya dönüştürme değerlendirmesi: 🟡\n\nOlumlu:\n* Bu modelin eğitim ve etkileşim yazılımı açık kaynak kodludur\n* Eğitilen model ücretsiz olarak kullanılabilir ve bu nedenle şirket içinde çalışabilir\n\nOlumsuz:\n* Eğitim verileri ücretsiz olarak kullanılamaz. Bu da dış tarafların önyargıyı kontrol etme ve düzeltme veya modelin başarımı ile CO2 kullanımını iyileştirme yeteneğini sınırlar.\n\n### LocalAI ile metin oluşturma değerlendirmesi: 🟢\n\nOlumlu:\n* Bu modelin eğitim ve etkileşim yazılımı açık kaynak kodludur\n* Eğitilmiş model ücretsiz olarak kullanılabilir ve bu nedenle şirket içinde çalıştırılabilir\n* Eğitim verileri serbestçe kullanılabilir. Önyargı kontrol edilebilir, düzeltilebilir ve başarım ile CO2 kullanımı iyileştirilebilir.\n\n\n### LocalAI ile Stable Difusion kullanarak görsel oluşturma değerlendirmesi: 🟡\n\nOlumlu:\n* Bu modelin eğitim ve etkileşim yazılımı açık kaynak kodludur\n* Eğitilmiş model ücretsiz olarak kullanılabilir ve bu nedenle şirket içinde çalıştırılabilir\n\nOlumsuz:\n* Eğitim verileri ücretsiz olarak kullanılamaz. Bu da dış tarafların önyargıyı kontrol etme ve düzeltme veya modelin başarımı ile CO2 kullanımını iyileştirme yeteneğini sınırlar.\n\n\n### LocalAI ile Whisper kullanarak konuşmadan yazıya dönüştürme değerlendirmesi: 🟡\n\nOlumlu:\n* Bu modelin eğitim ve etkileşim yazılımı açık kaynak kodludur\n* Eğitilmiş model ücretsiz olarak kullanılabilir ve bu nedenle şirket içinde çalıştırılabilir\n\nOlumsuz:\n* Eğitim verileri ücretsiz olarak kullanılamaz. Bu da dış tarafların önyargıyı kontrol etme ve düzeltme veya modelin başarımı ile CO2 kullanımını iyileştirme yeteneğini sınırlar.\n\n[Günlüğümüzden](https://nextcloud.com/blog/nextcloud-ethical-ai-rating/) Nextcloud Etik AI değerlendirmesi hakkında ayrıntılı bilgi alabilirsiniz.",
    "JSON object. Check the API documentation to get the list of all available parameters. For example: {example}" : "JSON nesnesi. Kullanılabilecek parametrelerin listesi için API belgelerine bakın. Örnek: {example}",
    "Must be in 256x256 format (default is {default})" : "256x256 boyutunda olmalıdır (varsayılan değer: {default})",
    "Failed to load models" : "Modeller yüklenemedi",
    "Failed to load quota info" : "Kota bilgileri yüklenemedi",
    "OpenAI admin options saved" : "OpenAI yönetici ayarları kaydedildi",
    "Failed to save OpenAI admin options" : "OpenAI yönetici ayarları kaydedilemedi",
    "The Assistant app is not enabled. You need it to use the features provided by the OpenAI/LocalAI integration app." : "Yardımcı uygulaması kullanıma alınmamış. OpenAI/LocalAI bütünleştirme uygulamasının sağladığı özellikleri kullanmak için bu uygulama gereklidir.",
    "Assistant app" : "Yardımcı uygulaması",
    "Services with an OpenAI-compatible API:" : "OpenAI uyumlu API kullanan hizmetler:",
    "Service URL" : "Hizmet adresi",
    "Example: {example}" : "Örnek: {example}",
    "Leave empty to use {openaiApiUrl}" : "{openaiApiUrl} kullanmak için boş bırakın",
    "With the current configuration, the target URL used to get the models is:" : "Geçerli yapılandırmada modelleri almak için kullanılan hedef adres:",
    "This should include the address of your LocalAI instance (or any service implementing an API similar to OpenAI) along with the root path of the API. This URL will be accessed by your Nextcloud server." : "Bu seçenekte, API kök klasörünün yanında LocalAI kopyanızın (veya OpenAI benzeri bir API kullanan herhangi bir hizmetin) adresi bulunmalıdır. Nextcloud sunucunuz bu adrese erişecektir.",
    "This can be a local address with a port like {example}. In this case, make sure 'allow_local_remote_servers' is set to true in config.php." : "Bu seenek, {example} gibi bağlantı noktası ile bir yerel adres olabilir. Bu durumda config.php dosyasında 'allow_local_remote_servers' seçeneğinin true olarak ayarlandığından emin olun.",
    "Service name (optional)" : "Hizmet adı (isteğe bağlı)",
    "Example: LocalAI of university ABC" : "Örnek: ABC üniversitesinindeki LocalAI",
    "This name will be displayed as provider name in the AI admin settings" : "Bu ad, Yapay Zeka yönetici ayarlarında hizmet sağlayıcı adı olarak görüntülenir",
    "Request timeout (seconds)" : "İstek zaman aşımı (saniye)",
    "Timeout for the request to the external API" : "Dış API isteğinin zaman aşımı",
    "Authentication" : "Kimlik doğrulama",
    "Authentication method" : "Kimlik doğrulama yöntemi",
    "API key" : "API anahtarı",
    "Basic Authentication" : "Temel kimlik doğrulaması",
    "API key (mandatory with OpenAI)" : "API anahtarı (OpenAI için zorunlu)",
    "You can create an API key in your OpenAI account settings" : "OpenAI hesap ayarlarınızdan bir API anahtarı oluşturabilirsiniz",
    "Basic Auth user" : "Temel kimlik doğrulaması kullanıcı adı",
    "Basic Auth password" : "Temel kimlik doğrulaması parolası",
    "Text completion endpoint" : "Metin tamamlama uç birimi",
    "Chat completions" : "Sohbet tamamlamaları",
    "Completions" : "Tamamlamalar",
    "Selection of chat/completion endpoint is not available for OpenAI since it implicitly uses chat completions for \"instruction following\" fine-tuned models." : "Sohbet/tamamlama uç noktası OpenAI için seçilemez çünkü \"yönerge izleyen\" ince ayarlı modeller için sohbet tamamlamaları dolaylı olarak yapar.",
    "Using the chat endpoint may improve text generation quality for \"instruction following\" fine-tuned models." : "Sohbet uç noktasının kullanılması, \"yönergeyi izleyen\" ince ayarlı modeller için metin oluşturma kalitesini iyileştirebilir.",
    "Default completion model to use" : "Kullanılacak varsayılan tamamlama modeli",
    "More information about OpenAI models" : "OpenAI modelleri hakkında ayrıntılı bilgi",
    "More information about LocalAI models" : "LocalAI modelleri hakkında ayrıntılı bilgi",
    "Extra completion model parameters" : "Ek tamamlama modeli parametreleri",
    "Max input tokens per request" : "İstek başına giriş kodu sayısı",
    "Split the prompt into chunks with each chunk being no more than the specified number of tokens (0 disables chunking)" : "İstemi, her biri belirtilen kod sayısından fazla olmayacak şekilde parçalara ayırır (0 olduğunda parçalara ayrılmaz)",
    "Default image generation model to use" : "Kullanılacak varsayılan görsel oluşturma modeli",
    "No models to list" : "Görüntülenecek bir model yok",
    "Default image size" : "Varsayılan görsel boyutu",
    "Use authentication for image retrieval request" : "Görsel alma isteği için kimlik doğrulaması kullanılsın",
    "Default transcription model to use" : "Kullanılacak varsayılan yazıya dönüştürme modeli",
    "Usage limits" : "Kullanım sınırları",
    "Quota enforcement time period (days)" : "Kota dayatması zaman aralığı (gün)",
    "Usage quotas per time period" : "Dönem başına kullanım kotaları",
    "Quota type" : "Kota türü",
    "Per-user quota / period" : "Kullanıcı başına kota / dönem",
    "Current system-wide usage / period" : "Geçerli sistem geneli kullanım / dönem",
    "A per-user limit for usage of this API type (0 for unlimited)" : "Bu API türünün kullanımı ile ilgili kullanıcı başına sınır (sınırsız için 0)",
    "Max new tokens per request" : "İstek başına en fazla yeni kod sayısı",
    "Maximum number of new tokens generated for a single text generation prompt" : "Tek bir metin oluşturma istemi için oluşturulacak en fazla yeni kod sayısı",
    "Use \"{newParam}\" parameter instead of the deprecated \"{deprecatedParam}\"" : "Kullanımdan kaldırılan \"{deprecatedParam}\" yerine \"{newParam}\" parametresini kullanın",
    "Select enabled features" : "Kullanıma alınacak özellikleri seçin",
    "Translation provider (to translate Talk messages for example)" : "Çeviri hizmeti (örneğin Konuş iletilerini çevirmek için)",
    "Text processing providers (to generate text, summarize, context write etc...)" : "Metin işleme hizmeti sağlayıcıları (metin oluşturma, özetleme, bağlam yazma...)",
    "Image generation provider" : "Görsel oluşturma hizmeti sağlayıcısı",
    "Speech-to-text provider (to transcribe Talk recordings for example)" : "Yazıdan metni dönüştürme hizmeti (örneğin Konuş kayıtlarını yazıya dönüştürmek için)",
    "OpenAI options saved" : "OpenAI ayarları kaydedildi",
    "Failed to save OpenAI options" : "OpenAI ayarları kaydedilemedi",
    "Your administrator defined a custom service address" : "Yöneticiniz bir özel hizmet adresi tanımlamış",
    "Leave the API key empty to use the one defined by administrators" : "Yöneticiler tarafından tanımlanmış bir anahtarın kullanılması için API anahtarını boş bırakın",
    "You can create a free API key in your OpenAI account settings" : "OpenAI hesap ayarlarınızdan ücretsiz bir API anahtarı oluşturabilirsiniz",
    "Leave the username and password empty to use the ones defined by your administrator" : "Yönetici tarafından tanımlanmış kullanıcı adı ve parolanın kullanılması için boş bırakın",
    "Username" : "Kullanıcı adı",
    "your Basic Auth user" : "temel kimlik doğrulaması kullanıcı adınız",
    "Password" : "Parola",
    "your Basic Auth password" : "temel kimlik doğrulaması parolanız",
    "Usage quota info" : "Kullanım kotası bilgileri",
    "Usage" : "Kullanım",
    "Specifying your own API key will allow unlimited usage" : "Kendi API anahtarınızı yazarak sınırsız kullanıma geçebilirsiniz"
},
"nplurals=2; plural=(n > 1);");

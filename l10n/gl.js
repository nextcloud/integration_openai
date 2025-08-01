OC.L10N.register(
    "integration_openai",
    {
    "Invalid models response received" : "Recibiuse unha resposta non válida dos modelos",
    "Default" : "Predeterminado",
    "Text generation" : "Xeración de texto",
    "Image generation" : "Xeración de imaxes",
    "Audio transcription" : "Transcrición de son",
    "Unknown" : "Descoñecido",
    "tokens" : "testemuños",
    "images" : "Imaxes",
    "seconds" : "segundos",
    "Unknown error while retrieving quota usage." : "Produciuse un erro descoñecido ao recuperar o uso da cota.",
    "Text generation quota exceeded" : "Superouse a cota de xeración de texto",
    "Unknown text generation error" : "Produciuse un erro descoñecido de xeración de texto",
    "Could not read audio file." : "Non foi posíbel ler o ficheiro de son",
    "Audio transcription quota exceeded" : "Superouse a cota de transcrición de son",
    "Unknown audio trancription error" : "Produciuse un erro descoñecido de xeración de transcrición de son",
    "Image generation quota exceeded" : "Superouse a cota de xeración de imaxes",
    "Unknown image generation error" : "Produciuse un erro descoñecido de xeración de imaxe",
    "Bad HTTP method" : "Método HTTP incorrecto",
    "Bad credentials" : "Credenciais incorrectas",
    "API request error: " : "Produciuse un erro na solicitude da API:",
    "Maximum output words" : "Máximo de palabras de saída",
    "Model" : "Modelo",
    "Images" : "Imaxes",
    "Question" : "Pregunta",
    "Generated response" : "Resposta xerada",
    "The model used to generate the completion" : "O modelo utilizado para xerar a conclusión",
    "Speed" : "Velocidade",
    "Detect language" : "Detectar o idioma",
    "Friendlier" : "Máis amistoso",
    "More formal" : "Máis formal",
    "Funnier" : "Máis divertido",
    "More casual" : "Máis informal",
    "More urgent" : "Máis urxente",
    "The maximum number of words/tokens that can be generated in the completion." : "O número máximo de palabras/testemuños que se poden xerar na conclusión.",
    "Change Tone" : "Cambiar o ton",
    "Ask a question about your data." : "Formule unha pregunta sobre os seus datos.",
    "Input text" : "Texto de entrada",
    "Write a text that you want the assistant to rewrite in another tone." : "Escriba un texto que queira que sexa reformulado polo asistente noutro ton",
    "Desired tone" : "Ton desexado",
    "In which tone should your text be rewritten?" : "En que ton debe reescribirse o texto?",
    "The rewritten text in the desired tone, written by the assistant:" : "O texto reformulado no ton desexado, escrito polo asistente",
    "OpenAI's DALL-E 2" : "DALL-E 2 de OpenAI",
    "Size" : "Tamaño",
    "Optional. The size of the generated images. Must be in 256x256 format. Default is %s" : "Opcional. O tamaño das imaxes xeradas. Debe estar en formato 256 x 256. O predeterminado é %s",
    "The model used to generate the images" : "O modelo utilizado para xerar as imaxes",
    "Prompt" : "Indicación",
    "OpenAI and LocalAI integration" : "Integración de OpenAI e LocalAI",
    "Integration of OpenAI and LocalAI services" : "Integración de servizos de OpenAI e LocalAI",
    "JSON object. Check the API documentation to get the list of all available parameters. For example: {example}" : "Obxecto JSON. Consulte a documentación da API para obter a lista de todos os parámetros dispoñíbeis. Por exemplo: {example}",
    "Must be in 256x256 format (default is {default})" : "Debe estar en formato 256 x 256 (o predeterminado é {default})",
    "Failed to load models" : "Produciuse un fallo ao cargar os modelos",
    "Failed to load quota info" : "Produciuse un fallo ao cargar a información da cota",
    "OpenAI admin options saved" : "Gardáronse as opcións de administración de OpenAI",
    "Failed to save OpenAI admin options" : "Produciuse un fallo ao gardar as opcións de administración de OpenAI",
    "The Assistant app is not enabled. You need it to use the features provided by the OpenAI/LocalAI integration app." : "A aplicación asistente non está activada. Necesítaa para empregar as funcións que ofrece a aplicación de integración OpenAI/LocalAI.",
    "Assistant app" : "Aplicación de asistente",
    "Services with an OpenAI-compatible API:" : "Servizos cunha API compatíbel con OpenAI:",
    "Service URL" : "URL do servizo",
    "Example: {example}" : "Exemplo: {example}",
    "Leave empty to use {openaiApiUrl}" : "Deixe o campo baleiro para usar {openaiApiUrl}",
    "With the current configuration, the target URL used to get the models is:" : "Coa configuración actual, o URL de destino utilizado para obter os modelos é:",
    "This should include the address of your LocalAI instance (or any service implementing an API similar to OpenAI) along with the root path of the API. This URL will be accessed by your Nextcloud server." : "Isto debería incluír o enderezo da súa instancia de LocalAI (ou calquera servizo que implemente unha API semellante a OpenAI) xunto coa ruta raíz da API. O seu servidor Nextcloud accederá a este URL.",
    "This can be a local address with a port like {example}. In this case, make sure 'allow_local_remote_servers' is set to true in config.php." : "Este pode ser un enderezo local cun porto como {example}. Neste caso, asegúrese de que «allow_local_remote_servers» estea definido como true (verdadeiro) en config.php.",
    "Service name (optional)" : "Nome do servizo (opcional)",
    "Example: LocalAI of university ABC" : "Exemplo: LocalAI da universidade ABC",
    "This name will be displayed as provider name in the AI admin settings" : "Este nome amosarase como nome do provedor na configuración do administrador da IA",
    "Request timeout (seconds)" : "Tempo de espera da solicitude (segundos)",
    "Timeout for the request to the external API" : "Tempo de espera para a solicitude á API externa",
    "Authentication" : "Autenticación",
    "Authentication method" : "Método de autenticación",
    "API key" : "Chave da API",
    "Basic Authentication" : "Autenticación básica",
    "API key (mandatory with OpenAI)" : "Chave da API (obrigatoria con OpenAI)",
    "You can create an API key in your OpenAI account settings" : "Pode crear unha chave API nos axustes da súa conta OpenAI",
    "Basic Auth user" : "Usuario de autenticación básica",
    "Basic Auth password" : "Contrasinal de autenticación básica",
    "Text completion endpoint" : "Punto final de conclusión do texto",
    "Chat completions" : "Conclusións da parola",
    "Completions" : "Conclusións",
    "Selection of chat/completion endpoint is not available for OpenAI since it implicitly uses chat completions for \"instruction following\" fine-tuned models." : "A selección do punto final de parola/conclusión non está dispoñíbel para OpenAI xa que utiliza implícitamente as conclusións da parola para os modelos axustados de «seguimento de instrucións».",
    "Using the chat endpoint may improve text generation quality for \"instruction following\" fine-tuned models." : "Usar o punto final de parola pode mellorar a calidade da xeración de texto para os modelos afinados que «seguen instrucións».",
    "Default completion model to use" : "Modelo predeterminado de conclusión a empregar",
    "More information about OpenAI models" : "Máis información sobre os modelos OpenAI",
    "More information about LocalAI models" : "Máis información sobre os modelos LocalAI",
    "Extra completion model parameters" : "Parámetros adicionais do modelo de remate",
    "Max input tokens per request" : "Número máximo de testemuños de entrada por solicitude",
    "Split the prompt into chunks with each chunk being no more than the specified number of tokens (0 disables chunking)" : "Dividir a idicación en anacos, sendo cada anaco non superior ao número especificado de testemuños (0 desactiva a fragmentación)",
    "Default image generation model to use" : "Modelo de xeración de imaxes predeterminado a empregar",
    "No models to list" : "Non hai modelos para enumerar",
    "Default image size" : "Tamaño predeterminado de imaxe",
    "Use authentication for image retrieval request" : "Empregue a autenticación para a solicitude de recuperación de imaxes",
    "Default transcription model to use" : "Modelo de transcrición predeterminado a empregar",
    "Usage limits" : "Límites de uso",
    "Quota enforcement time period (days)" : "Período de execución da cota (días)",
    "Usage quotas per time period" : "Cotas de uso por período de tempo",
    "Quota type" : "Tipo de cota",
    "Per-user quota / period" : "Cota/período por usuario",
    "Current system-wide usage / period" : "Uso/período actual en todo o sistema",
    "A per-user limit for usage of this API type (0 for unlimited)" : "Un límite por usuario para o uso deste tipo de API (0 para ilimitado)",
    "Select enabled features" : "Seleccione as funcionalidades activadas",
    "Translation provider (to translate Talk messages for example)" : "Provedor de tradución (para traducir mensaxes de Parladoiro, por exemplo)",
    "Image generation provider" : "Provedor de xeración de imaxes",
    "Speech-to-text provider (to transcribe Talk recordings for example)" : "Provedor de conversión de voz a texto (para transcribir as gravacións de Parladoiro, por exemplo)",
    "OpenAI options saved" : "Gardáronse as opcións de OpenAI",
    "Failed to save OpenAI options" : "Produciuse un fallo ao gardar as opcións de OpenAI",
    "Your administrator defined a custom service address" : "A administración da instancia definiu un enderezo de servizo personalizado",
    "Leave the API key empty to use the one defined by administrators" : "Deixe a chave API baleira para usar a definida pola administración da instancia",
    "You can create a free API key in your OpenAI account settings" : "Pode crear unha chave API nos axustes da súa conta OpenAI:",
    "Leave the username and password empty to use the ones defined by your administrator" : "Deixe o nome de usuario e o contrasinal baleiros para usar os definidos pola administración da instancia",
    "Usage quota info" : "Información sobre a cota de uso",
    "Usage" : "Uso",
    "Specifying your own API key will allow unlimited usage" : "Especificar a súa propia chave da API permitiralle un uso ilimitado",
    "Use \"{newParam}\" parameter instead of the deprecated \"{deprecatedParam}\"" : "Empregue o parámetro «{newParam}» en troques do obsoleto «{deprecatedParam}»"
},
"nplurals=2; plural=(n != 1);");

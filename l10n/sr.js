OC.L10N.register(
    "user_oidc",
    {
    "Login with %1s" : "Пријави се са %1s",
    "ID4Me is disabled" : "ID4Me је искључен",
    "Invalid OpenID domain" : "Неисправан OpenID домен",
    "Invalid authority issuer" : "Неисправан издавач ауторитета",
    "Multiple authority found" : "Пронађено је више ауторитета",
    "The received state does not match the expected value." : "Примљено стање се не подудара са очекиваном вредности.",
    "Authority not found" : "Није пронађен ауторитет",
    "Failed to decrypt the ID4ME provider client secret" : "Није успело дешифровање клијентске тајне пружаоца ID4ME услуге",
    "The received token is expired." : "Важност примљеног жетона је истекла-",
    "The audience does not match ours" : "Публика не одговара нашој",
    "The authorized party does not match ours" : "Овлашћена страна не одговара нашој",
    "No authorized party" : "Нема овлашћене стране",
    "The nonce does not match" : "Нонс се не слаже",
    "You must access Nextcloud with HTTPS to use OpenID Connect." : "Да бисте користили OpenID Connect, Nextcloud серверу мора да се приступа преко HTTPS.",
    "There is no such OpenID Connect provider." : "Не постоји такав пружалац OpenID Connect услуге.",
    "Could not reach the OpenID Connect provider." : "Не може да се приступи пружаоцу OpenID Connect услуге.",
    "Failed to decrypt the OIDC provider client secret" : "Није успело дешифровање клијентске тајне пружаоца OIDC услуге",
    "Failed to contact the OIDC provider token endpoint" : "Није успело контактирање крајње тачке жетона пружаоца OIDC услуге",
    "The issuer does not match the one from the discovery endpoint" : "Издавач није исти као онај који је добијен из крајње тачке за откривање",
    "Failed to provision the user" : "Није успело снабдевање корисника",
    "You do not have permission to log in to this instance. If you think this is an error, please contact an administrator." : "Немате права да се пријавите на ову инстанцу. Ако мислите да је ово грешка, молимо вас да се обратите администратору.",
    "User conflict" : "Конфликт корисника",
    "OpenID Connect" : "OpenID Connect",
    "OpenID Connect user backend" : "OpenID Connect кориснички позадински механизам",
    "Use an OpenID Connect backend to login to your Nextcloud" : "Користите OpenID Connect позадински механизам да се пријавите на свој Nextcloud",
    "Allows flexible configuration of an OIDC server as Nextcloud login user backend." : "Омогућава прилагодљиву конфигурација OIDC сервера као корисничког позадинског механизма за пријаву на Nextcloud.",
    "Could not save ID4me state: {msg}" : "Није могло да се сачува ID4me: {msg} ",
    "Could not update the provider:" : "Није могао да се ажурира пружалац услуге:",
    "Could not remove provider: {msg}" : "Не може да се уклони пружалац услуге: {msg}",
    "Could not register provider:" : "Не може да се региструје пружалац услуге:",
    "Allows users to authenticate via OpenID Connect providers." : "Омогућава проверу идентитета корисника преко пружалаца OpenID Connect услуге.",
    "Enable ID4me" : "Укључи ID4me",
    "Registered Providers" : "Регистровани пружаоци услуге",
    "Register new provider" : "Региструј новог пружаоца услуге",
    "Register a new provider" : "Регистрација новог пружаоца услуге",
    "Configure your provider to redirect back to {url}" : "Подесите свог пружаоца услуге да преусмери назад на {url}",
    "No providers registered." : "Није регистрован ниједан пружалац услуге.",
    "Client ID" : "ID клијента",
    "Discovery endpoint" : "Крајња тачка за откривање",
    "Backchannel Logout URL" : "URL позадинског канала за одјављивање",
    "Redirect URI (to be authorized in the provider client configuration)" : "URI за преусмеравање (треба да се овласти у клијентској конфигурацији пружаоца услуге)",
    "Update" : "Ажурирај",
    "Remove" : "Уклони",
    "Update provider settings" : "Ажурирај подешавања пружаоца услуге",
    "Update provider" : "Ажурирај пружаоца услуге",
    "Submit" : "Пошаљи",
    "Client configuration" : "Конфигурација клијента",
    "Identifier (max 128 characters)" : "Идентификатор (макс 128 знакова)",
    "Display name to identify the provider" : "Име за приказ које идентификује пружаоца услуге",
    "Client secret" : "Тајна клијента",
    "Leave empty to keep existing" : "Оставите празне да се задржи постојеће",
    "Warning, if the protocol of the URLs in the discovery content is HTTP, the ID token will be delivered through an insecure connection." : "Упозорење, ако је протокол URL адреса у садржају откривања HTTP, ID жетон ће се доставити кроз везу која није безбедна.",
    "Custom end session endpoint" : "Прилагођена крајња тачка краја сесије",
    "Scope" : "Опсег",
    "Extra claims" : "Екстра тврдње",
    "Attribute mapping" : "Мапирање атрибута",
    "User ID mapping" : "Мапирање ID корисника",
    "Quota mapping" : "Мапирање квоте",
    "Groups mapping" : "Мапирање група",
    "Extra attributes mapping" : "Мапирање екстра атрибута",
    "Display name mapping" : "Мапирање имена за приказ",
    "Gender mapping" : "Мапирање пола",
    "Email mapping" : "Мапирање и-мејл адресе",
    "Phone mapping" : "Мапирање телефона",
    "Language mapping" : "Мапирање језика",
    "Role/Title mapping" : "Мапирање улоге/звања",
    "Street mapping" : "Мапирање улице",
    "Postal code mapping" : "Мапирање поштанског броја",
    "Locality mapping" : "Мапирање локалитета",
    "Region mapping" : "Мапирање региона",
    "Country mapping" : "Мапирање државе",
    "Organisation mapping" : "Мапирање организације",
    "Website mapping" : "Мапирање веб сајта",
    "Avatar mapping" : "Мапирање аватара",
    "Biography mapping" : "Мапирање биографије",
    "X (formerly Twitter) mapping" : "X (бивши Twitter) мапирање",
    "Fediverse/Nickname mapping" : "Fediverse/Надимак мапирање",
    "Headline mapping" : "Мапирање наслова",
    "Authentication and Access Control Settings" : "Подешавања контроле приступа и потврде идентитета",
    "Use unique user ID" : "Користи јединствени ID корисника",
    "By default every user will get a unique user ID that is a hashed value of the provider and user ID. This can be turned off but uniqueness of users accross multiple user backends and providers is no longer preserved then." : "Подразумевано ће сваки корисник добити јединствени ID корисника који представља хеширану вредност пружаоца услуге и ID корисника. Ово може да се искључи, али онда се више не очувава јединственост корисника по различитим корисничким позадинским механизмима и  пружаоцима услуге.",
    "Use provider identifier as prefix for IDs" : "Користи идентификатор пружаоца услуге као префикс за ID",
    "To keep IDs in plain text, but also preserve uniqueness of them across multiple providers, a prefix with the providers name is added." : "Да би ID остао у чистом тексту, али да се ипак очува њихова јединственост по различитим пружаоцима услуге, додаје се префикс са именом пружаоца услуге.",
    "Use group provisioning." : "Користи достављање групе.",
    "This will create and update the users groups depending on the groups claim in the ID token. The Format of the groups claim value should be {sample1}, {sample2} or {sample3}" : "Ово ће да креира и ажурира припадност корисника групи у зависности од тврдње групе у ID жетону. Формат вредности за припадност групи би требало да буде {sample1}, {sample2} или {sample3}",
    "Group whitelist regex" : "Регуларни израз дозвољених група",
    "Only groups matching the whitelist regex will be created, updated and deleted by the group claim. For example: {regex} allows all groups which ID starts with {substr}" : "Тврдња групе ће да креира, ажурира и обрише само групе које задовољавају овај регуларни израз. На пример: {regex} дозвољава све групе чији ID почиње са {substr}",
    "Restrict login for users that are not in any whitelisted group" : "Спречи пријављивање корисницима који се не налазе ни у једној од дозвољених група",
    "Users that are not part of any whitelisted group are not created and can not login" : "Корисници који нису део ниједне од дозвољених група се не креирају и не могу да се пријаве",
    "Check Bearer token on API and WebDAV requests" : "Проверавај Bearer жетон у API и WebDAV захтевима",
    "Do you want to allow API calls and WebDAV request that are authenticated with an OIDC ID token or access token?" : "Желите ли да дозволите API позиве и WebDAV захтев који су потврђени  OIDC ID жетоном или приступним жетоном?",
    "Auto provision user when accessing API and WebDAV with Bearer token" : "Аутоматски доставља корисника када приступа ка API и WebDAV  са Bearer жетоном",
    "This automatically provisions the user, when sending API and WebDAV requests with a Bearer token. Auto provisioning and Bearer token check have to be activated for this to work." : "Ово аутоматски доставља корисника када се шаљу API и WebDAV захтеви са Bearer жетоном. Да би ово функционисало, аутоматско достављање и провера Bearer жетона морају да буду укључени.",
    "Send ID token hint on logout" : "Приликом одјављивања шаљи наговештај ID жетона",
    "Should the ID token be included as the id_token_hint GET parameter in the OpenID logout URL? Users are redirected to this URL after logging out of Nextcloud. Enabling this setting exposes the OIDC ID token to the user agent, which may not be necessary depending on the OIDC provider." : "Да ли би ID жетон требало да се укључи као id_token_hint GET параметар у OpenID URL адреси за одјављивање? Када се одјаве са  Nextcloud сервера, корисници се преусмеравају на ову URL адресу. Када се укључи ово подешавање, OIDC ID жетон се излаже корисничком агенту, а у зависности од пружаоца OIDC услуге, то можда није неопходно.",
    "Cancel" : "Откажи",
    "Domain" : "Домен",
    "your.domain" : "ваш.домен"
},
"nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);");

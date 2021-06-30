# user_oidc

OIDC connect user backend for Nextcloud


## Building the app

Requirements for building:
- Node.js 14
- NPM 7
- PHP
- composer

The app uses [krankerl](https://github.com/ChristophWurst/krankerl) to build the release archive from the git repository. 
The release will be put into `build/artifacts/` when running the `krankerl package`.

The app can also be built without krankerl by manually running:

```
composer install --no-dev -o
npm install
npm` run build
```

That would however require manual cleanup and moving into an archive.

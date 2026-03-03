# EarnApp Scanner

Aplicación para automatizar el escaneo de URLs de EarnApp, detectar coincidencias por palabras clave y eliminar automáticamente URLs de la cola cuando hay match.

## Características

- Escaneo automático con navegador real usando Playwright.
- Cola persistente de URLs en archivos JSON.
- Detección tolerante de coincidencias (`successful`, `successfully`, `successfull`, etc.).
- Control desde interfaz web PHP (`iniciar`, `detener`, estado en tiempo real).
- Persistencia de estado entre ejecuciones.

## Stack y dependencias

- PHP (backend de control y estado)
- Node.js (worker de automatización)
- Playwright + Playwright Core

Dependencias Node (ver `package.json`):

- `playwright`
- `playwright-core`

## Requisitos

- Windows (probado en entorno EasyPHP)
- PHP con cURL habilitado
- Node.js instalado
- Navegador Chromium/Edge/Chrome disponible

## Instalación

```bash
npm install
```

## Ejecución

1. Levanta el servidor PHP (EasyPHP).
2. Abre la app en navegador.
3. Carga URLs y palabras clave.
4. Presiona **Iniciar**.

## Versionado

Este proyecto usa **SemVer**: `MAJOR.MINOR.PATCH`.

- `PATCH`: correcciones y ajustes compatibles.
- `MINOR`: funcionalidades nuevas compatibles.
- `MAJOR`: cambios incompatibles.

Fuente de versión única:

- Archivo `VERSION`
- `package.json` (`version`)
- UI de la app (cabecera)
- GitHub Release tag (`Vx.x.x`)

## Flujo recomendado de release

1. Actualiza versión en `VERSION` y `package.json`.
2. Commit en `main`.
3. Push a `main`.
4. Workflow crea release automáticamente en GitHub.

## Licencia

Este proyecto está licenciado bajo [Apache License 2.0](LICENSE).

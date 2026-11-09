# Copilot Instructions for IDE

This package contains the `axenox.IDE` app, which is an add-on to the ExFace no-code platform for business web apps for providing integrated cloud development tools within the platform.

## The platform

The platform core is available in `<installation_path>/vendor/exface/core`. All [core instructions](../../../exface/core/.github/copilot-instructions.md) apply to this package as well!

## Global rules

- **Always follow** our [PHP](../../../exface/Core/Docs/developer_docs/code_conventions/PHP/Conventions.md) guidelines when writing PHP code
- **Always follow** [UXON instructions](../../../exface/Core/.github/instructions/uxon.instructions.md) when working with configurable model prototypes.
- Ask the **Workbench code reviewer** agent to review your changes and fix issues required for completion before you report your task as done.

## Documentation

Documentation is available in the `Docs` folder.

- Follow the general [documentation instructions](../../../exface/core/.github/instructions/documentation.instructions.md)
- Write documentation in english only
- place dev-oriented documentation in the `Docs/Development` folder. The other folders are for app designers!

## Testing

Follow the [Core testing instructions](../../../exface/core/.github/instructions/testing.instructions.md)
when implementing or fixing PHP behavior or adding tests. Add or update focused
PHPUnit coverage wherever this does not require mocking Workbench.

Reuse this app's existing unit and integration suites, bootstrap and fixtures.
Keep PHPUnit tests under `Tests/PHPUnit`, alongside other test frameworks' folders.
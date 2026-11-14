<?php

namespace ElChoco\ExtensionUninstaller;

/** Carries a translation key without booting Flarum in the CLI worker. */
class ConsoleMessage extends \RuntimeException
{
    public string $translationKey;
    public array $translationParameters;

    public function __construct(string $key, string $fallback, array $parameters = [])
    {
        $this->translationKey = 'el-choco-extension-uninstaller.admin.'.$key;
        $this->translationParameters = $parameters;
        parent::__construct(self::format($fallback, $parameters));
    }

    public static function format(string $fallback, array $parameters): string
    {
        $replace = [];
        foreach ($parameters as $key => $value) { $replace['{'.$key.'}'] = (string) $value; }
        return strtr($fallback, $replace);
    }

    public static function payload(\Throwable $error): array
    {
        $data = ['message' => $error->getMessage()];
        if ($error instanceof self) {
            $data['translationKey'] = $error->translationKey;
            $data['translationParameters'] = $error->translationParameters;
        }
        return $data;
    }
}

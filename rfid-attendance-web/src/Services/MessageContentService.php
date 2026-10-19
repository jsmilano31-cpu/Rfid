<?php
declare(strict_types=1);
namespace App\Services;

use DomainException;

final class MessageContentService
{
    /** Rich messages are purified on input and output. Legacy bodies remain plain text. */
    public static function clean(string $html): string
    {
        if (strlen($html) > 65535) throw new DomainException('The formatted message is too long (maximum 65 KB).');
        require_once BASE_PATH . '/vendor/htmlpurifier/HTMLPurifier.standalone.php';
        static $purifier;
        if (!$purifier) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Cache.DefinitionImpl', null);
            $config->set('HTML.Allowed', 'p[style],br,strong,b,em,i,u,s,strike,sub,sup,h1,h2,h3,ul,ol,li[style],blockquote,pre,code,span[style],a[href|title]');
            $config->set('CSS.AllowedProperties', ['text-align','padding-left','color','background-color']);
            $config->set('URI.AllowedSchemes', ['https'=>true,'http'=>true,'mailto'=>true]);
            $config->set('HTML.Nofollow', true);
            $purifier = new \HTMLPurifier($config);
        }
        return $purifier->purify($html);
    }

    public static function text(string $html): string
    {
        $text = preg_replace('~</(?:p|h[1-3]|li|blockquote|pre)>|<br\s*/?>~i', "\n", $html);
        $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(str_replace("\u{00A0}", ' ', $text));
    }
}

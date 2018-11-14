<?php
namespace Neos\Fusion\Afx\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Utility\Arrays;
use PackageFactory\Afx\Parser as AfxParser;
use Neos\Fusion\Afx\Exception\AfxException;

/**
 * Class AfxService
 *
 * @Flow\Scope("singleton")
 */
class AfxService
{
    const INDENTATION = '    ';

    /**
     * @var string $afxCode the AFX code that is converted
     * @var string $indentation Indentation to start with
     * @return string
     */
    public static function convertAfxToFusion($afxCode, $indentation = '')
    {
        $parser = new AfxParser(trim($afxCode));
        $ast = $parser->parse();
        $fusion = self::astNodeListToFusion($ast, $indentation);
        return $fusion;
    }

    /**
     * @param array $astNode
     * @param string $indentation
     * @return string
     */
    protected static function astToFusion($ast, $indentation = '')
    {
        switch ($ast['type']) {
            case 'expression':
                return self::astExpressionToFusion($ast['payload'], $indentation);
                break;
            case 'string':
                return self::astStringToFusion($ast['payload'], $indentation);
                break;
            case 'text':
                return self::astTextToFusion($ast['payload'], $indentation);
                break;
            case 'boolean':
                return self::astBooleanToFusion($ast['payload'], $indentation);
                break;
            case 'node':
                return self::astNodeToFusion($ast['payload'], $indentation);
                break;
            default:
                throw new AfxException(sprintf('ast type %s is unkonwn', $ast['type']));
        }
    }

    /**
     * @param array $payload
     * @param string $indentation
     * @return string
     */
    protected static function astBooleanToFusion($payload, $indentation = '')
    {
        return 'true';
    }

    /**
     * @param array $payload
     * @param string $indentation
     * @return string
     */
    protected static function astExpressionToFusion($payload, $indentation = '')
    {
        return '${' . $payload . '}';
    }

    /**
     * @param array $payload
     * @param string $indentation
     * @return string
     */
    protected static function astStringToFusion($payload, $indentation = '')
    {
        return '\'' . str_replace('\'', '\\\'', $payload) . '\'';
    }

    /**
     * @param array $payload
     * @param string $indentation
     * @return string
     */
    protected static function astTextToFusion($payload, $indentation = '')
    {
        return '\'' . addslashes($payload) . '\'';
    }

    /**
     * @param array $payload
     * @param string $indentation
     * @return string
     */
    protected static function astNodeToFusion($payload, $indentation = '')
    {
        $tagName = $payload['identifier'];

        $pathChildren = [];
        $contentChildren = [];

        if ($payload['children'] && count($payload['children']) > 0) {
            foreach ($payload['children'] as $child) {
                if ($child['type'] === 'node' && array_key_exists('@path', $child['payload']['props'])) {
                    if ($child['payload']['props']['@path']['type'] === 'string') {
                        $pathChildren[$child['payload']['props']['@path']['payload']] = $child;
                        continue;
                    } else {
                        throw new AfxException(
                            sprintf('@path only supports string payloads %s found', $child['payload']['props']['@path']['type'])
                        );
                    }
                }
                $contentChildren[] = $child;
            }
        }

        // Tag
        if (strpos($tagName, ':') !== false) {
            // Named fusion-object
            $fusion = $tagName . ' {' . PHP_EOL;
            // Attributes are not prefixed
            $attributePrefix = '';
        } else {
            // Neos.Fusion:Tag
            $fusion = 'Neos.Fusion:Tag {' . PHP_EOL;
            $fusion .= $indentation . self::INDENTATION .'tagName = \'' .  $tagName . '\'' . PHP_EOL;
            // Attributes are rendered as tag-attributes
            $attributePrefix = 'attributes.';
            // Self closing Tags stay self closing
            if ($payload['selfClosing'] === true) {
                $fusion .= $indentation . self::INDENTATION .'selfClosingTag = true' . PHP_EOL;
            }
        }

        // Attributes
        if ($payload['props'] && count($payload['props']) > 0) {
            foreach ($payload['props'] as $propName => $prop) {
                if ($propName === '@key' || $propName === '@children' || $propName === '@path') {
                    continue;
                } else {
                    if ($propName{0} === '@') {
                        $fusionName = $propName;
                    } else {
                        $fusionName = $attributePrefix . $propName;
                    }
                    $propFusion = self::astToFusion($prop, $indentation . self::INDENTATION);
                    if ($propFusion !== null) {
                        $fusion .= $indentation . self::INDENTATION . $fusionName . ' = ' . $propFusion . PHP_EOL;
                    }
                }
            }
        }

        // Path Children
        if ($pathChildren !== []) {
            foreach ($pathChildren as $path => $child) {
                $fusion .= $indentation . self::INDENTATION . $path . ' = ' . self::astToFusion($child, $indentation . self::INDENTATION) . PHP_EOL;
            }
        }

        // Content Children
        if ($contentChildren !== []) {
            $childrenProp = Arrays::getValueByPath($payload, 'props.@children');
            if ($childrenProp) {
                if ($childrenProp['type'] === 'string') {
                    $childrenPropertyName = $childrenProp['payload'];
                } else {
                    throw new AfxException(
                        sprintf('@children only supports string payloads %s found', $childrenProp['type'])
                    );
                }
            } else {
                $childrenPropertyName = 'content';
            }

            $childFusion = self::astNodeListToFusion($contentChildren, $indentation . self::INDENTATION);
            if ($childFusion) {
                $fusion .= $indentation . self::INDENTATION . $childrenPropertyName . ' = ' . $childFusion . PHP_EOL;
            }
        }

        $fusion .= $indentation . '}';

        return $fusion;
    }

    /**
     * @param array $payload
     * @param string $indentation
     * @return string
     */
    protected static function astNodeListToFusion($payload, $indentation = '')
    {
        $index = 1;

        // ignore blank text if it is connected to a newline
        $payload = array_map(function ($astNode) {
            if ($astNode['type'] === 'text') {
                $astNode['payload'] = preg_replace('/[\\s]*\\n[\\s]*/u', '', $astNode['payload']);
            }
            return $astNode;
        }, $payload);

        // filter empty text nodes
        $payload = array_filter($payload, function ($astNode) {
            if ($astNode['type'] === 'text' && $astNode['payload'] == '') {
                return false;
            } else {
                return true;
            }
        });

        if (count($payload) === 0) {
            return '\'\'';
        } elseif (count($payload) === 1) {
            return self::astToFusion(array_shift($payload), $indentation);
        } else {
            $fusion = 'Neos.Fusion:Array {' . PHP_EOL;
            foreach ($payload as $astNode) {
                // detect key
                $fusionName = 'item_' . $index;
                if ($keyProperty = Arrays::getValueByPath($astNode, 'payload.props.@key')) {
                    if ($keyProperty['type'] === 'string') {
                        $fusionName = $keyProperty['payload'];
                    } else {
                        throw new AfxException(
                            sprintf(
                                '@key only supports string payloads %s was given',
                                $keyProperty['type']
                            )
                        );
                    }
                }

                // convert node
                $nodeFusion = self::astToFusion($astNode, $indentation . self::INDENTATION);
                if ($nodeFusion !== null) {
                    $fusion .= $indentation . self::INDENTATION . $fusionName . ' = ' . $nodeFusion . PHP_EOL;
                    $index++;
                }
            }
            $fusion .= $indentation . '}';
            return $fusion;
        }
    }
}

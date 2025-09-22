<?php
function XMLtoArray($xml) {
    $previous_value = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false; 
    $dom->loadXml($xml);
    libxml_use_internal_errors($previous_value);
    if (libxml_get_errors()) {
        return [];
    }
    return DOMtoArray($dom);
}

function DOMtoArray($root) {
    $result = array();

    if ($root->hasAttributes()) {
        $attrs = $root->attributes;
        foreach ($attrs as $attr) {
            $result['@attributes'][$attr->name] = $attr->value;
        }
    }

    if ($root->hasChildNodes()) {
        $children = $root->childNodes;
        if ($children->length == 1) {
            $child = $children->item(0);
            if (in_array($child->nodeType,[XML_TEXT_NODE,XML_CDATA_SECTION_NODE])) {
                $result['_value'] = $child->nodeValue;
                return count($result) == 1
                    ? $result['_value']
                    : $result;
            }

        }
        $groups = array();
        foreach ($children as $child) {
            if (!isset($result[$child->nodeName])) {
                $result[$child->nodeName] = DOMtoArray($child);
            } else {
                if (!isset($groups[$child->nodeName])) {
                    $result[$child->nodeName] = array($result[$child->nodeName]);
                    $groups[$child->nodeName] = 1;
                }
                $result[$child->nodeName][] = DOMtoArray($child);
            }
        }
    }
    return $result;
}
class Array2XML
{
    /**
     * @var string
     */
    private static $encoding = 'UTF-8';

    /**
     * @var DomDocument|null
     */
    private static $xml = null;

    /**
     * Convert an Array to XML.
     *
     * @param string $node_name - name of the root node to be converted
     * @param array  $arr       - array to be converted
     * @param array  $docType   - optional docType
     *
     * @return DomDocument
     * @throws Exception
     */
    public static function createXML($node_name, $arr = [], $docType = [])
    {
        $xml = self::getXMLRoot();
        // BUG 008 - Support <!DOCTYPE>
        if ($docType) {
            $xml->appendChild(
                (new DOMImplementation())
                    ->createDocumentType(
                        $docType['name'] ?? '',
                        $docType['publicId'] ?? '',
                        $docType['systemId'] ?? ''
                    )
            );
        }
        $xml->appendChild(self::convert($node_name, $arr));
        self::$xml = null;    // clear the xml node in the class for 2nd time use.
        return $xml;
    }

    /**
     * Initialize the root XML node [optional].
     *
     * @param string $version
     * @param string $encoding
     * @param bool   $standalone
     * @param bool   $format_output
     */
    public static function init($version = '1.0', $encoding = 'utf-8', $standalone = false, $format_output = true)
    {
        self::$xml = new DomDocument($version, $encoding);
        self::$xml->xmlStandalone = $standalone;
        self::$xml->formatOutput = $format_output;
        self::$encoding = $encoding;
    }

    /**
     * Get string representation of boolean value.
     *
     * @param mixed $v
     *
     * @return string
     */
    private static function bool2str($v)
    {
        //convert boolean to text value.
        $v = $v === true ? 'true' : $v;
        $v = $v === false ? 'false' : $v;
        return $v;
    }

    /**
     * Convert an Array to XML.
     *
     * @param string $node_name - name of the root node to be converted
     * @param array  $arr       - array to be converted
     *
     * @return DOMNode
     *
     * @throws Exception
     */
    private static function convert($node_name, $arr = [])
    {
        //print_arr($node_name);
        $xml = self::getXMLRoot();
        $node = $xml->createElement($node_name);

        if (is_array($arr)) {
            // get the attributes first.;
            if (array_key_exists('@attributes', $arr) && is_array($arr['@attributes'])) {
                foreach ($arr['@attributes'] as $key => $value) {
                    if (!self::isValidTagName($key)) {
                        throw new Exception('[Array2XML] Illegal character in attribute name. attribute: '.$key.' in node: '.$node_name);
                    }
                    $node->setAttribute($key, self::bool2str($value));
                }
                unset($arr['@attributes']); //remove the key from the array once done.
            }
            // check if it has a value stored in @value, if yes store the value and return
            // else check if its directly stored as string
            if (array_key_exists('@value', $arr)) {
                $node->appendChild($xml->createTextNode(self::bool2str($arr['@value'])));
                unset($arr['@value']);    //remove the key from the array once done.
                //return from recursion, as a note with value cannot have child nodes.
                return $node;
            } elseif (array_key_exists('@cdata', $arr)) {
                $node->appendChild($xml->createCDATASection(self::bool2str($arr['@cdata'])));
                unset($arr['@cdata']);    //remove the key from the array once done.
                //return from recursion, as a note with cdata cannot have child nodes.
                return $node;
            }
        }

        //create subnodes using recursion
        if (is_array($arr)) {
            // recurse to get the node for that key
            foreach ($arr as $key => $value) {
                if (!self::isValidTagName($key)) {
                    throw new Exception('[Array2XML] Illegal character in tag name. tag: '.$key.' in node: '.$node_name);
                }
                if (is_array($value) && is_numeric(key($value))) {
                    // MORE THAN ONE NODE OF ITS KIND;
                    // if the new array is numeric index, means it is array of nodes of the same kind
                    // it should follow the parent key name
                    foreach ($value as $k => $v) {
                        $node->appendChild(self::convert($key, $v));
                    }
                } else {
                    // ONLY ONE NODE OF ITS KIND
                    $node->appendChild(self::convert($key, $value));
                }
                unset($arr[$key]); //remove the key from the array once done.
            }
        }

        // after we are done with all the keys in the array (if it is one)
        // we check if it has any text value, if yes, append it.
        if (!is_array($arr)) {
            $node->appendChild($xml->createTextNode(self::bool2str($arr)));
        }
        return $node;
    }

    /**
     * Get the root XML node, if there isn't one, create it.
     *
     * @return DomDocument|null
     */
    private static function getXMLRoot()
    {
        if (empty(self::$xml)) {
            self::init();
        }
        return self::$xml;
    }

    /**
     * Check if the tag name or attribute name contains illegal characters
     * Ref: http://www.w3.org/TR/xml/#sec-common-syn.
     *
     * @param string $tag
     *
     * @return bool
     */
    private static function isValidTagName($tag)
    {
        $pattern = '/^[a-z_]+[a-z0-9\:\-\.\_]*[^:]*$/i';
        return preg_match($pattern, $tag, $matches) && $matches[0] == $tag;
    }
}
function array_from_xml_file($file) {
	$xml = preg_replace('/<!--(.|\s)*?-->/', '', file_get_contents($file)); # strip comments
	return XMLtoArray($xml);
}
function array_to_xml($array) {
	$xml = Array2XML::createXML('root_node_name', $array);
	$xml = $xml->saveXML();
	$xml = str_replace("<root_node_name>", "", $xml);
	$xml = str_replace("</root_node_name>", "", $xml);
	$xml = str_replace("<_value>", "", $xml);
	$xml = str_replace("</_value>", "", $xml);
	$xml = str_replace(">\n      ", ">", $xml);
	$xml = str_replace("\n    </", "</", $xml);
	$xml = str_replace('<?xml version="1.0" encoding="utf-8" standalone="no"?>', "", $xml);
	return '<?xml version="1.0" encoding="UTF-8" ?>' . PHP_EOL . trim($xml);
}
function dumpAsXML($array, $logFile) {
	$xml = array_to_xml($array);
	header('Content-type: application/xml');
	echo $xml;
	if (strlen($logFile) > 0) {
		error_log($xml, 3, $logFile);
	}
}
function insertBefore($input, $index, $newKey, $element) {
    if (!array_key_exists($index, $input)) {
        throw new Exception("Index not found");
    }
    $tmpArray = array();
    foreach ($input as $key => $value) {
        if ($key === $index) {
            $tmpArray[$newKey] = $element;
        }
        $tmpArray[$key] = $value;
    }
    return $tmpArray;
}
function insertAfter($input, $index, $newKey, $element) {
    if (!array_key_exists($index, $input)) {
        throw new Exception("Index not found");
    }
    $tmpArray = array();
    foreach ($input as $key => $value) {
        $tmpArray[$key] = $value;
        if ($key === $index) {
            $tmpArray[$newKey] = $element;
        }
    }
    return $tmpArray;
}
?>
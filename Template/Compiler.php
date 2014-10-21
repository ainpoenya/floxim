<?php

namespace Floxim\Floxim\Template;

use Floxim\Floxim\System\Fx as fx;

/*
 * Transform token tree into php code
 */
class Compiler {
    protected $template_set_name = null;
    
    /**
     * Convert the tree of tokens in the php code
     * @param string $tree source code of the template
     * @return string of php code
     */
    public function compile($tree, $class_name) {
        $code = $this->makeCode($tree, $class_name);
        $code = self::addTabs($code);
        if (fx::config('templates.check_php_syntax')) {
            $is_correct = self::isPhpSyntaxCorrect($code);
            if ($is_correct !== true) {
                $error_line = $is_correct[1][1];
                $lines = explode("\n", $code);
                $lines[ $error_line - 1] = '[[bad '.$lines[$error_line - 1].']]';
                $lined = join("\n", $lines);
                $error = $is_correct[0].': '.$is_correct[1][0].' (line '.$error_line.')';
                fx::debug($error, $lined);
                fx::log($error, $is_correct, $lined);
                throw new \Exception('Syntax error');
            }
        }
        return $code;
    }
    
    public static function addTabs($code) {
        $res = '';
        $level = 0;
        $code = preg_split("~[\n\r]+~", $code);
        foreach ($code as $s) {
            $s = trim($s);
            if (preg_match("~^[\}\)]~", $s)) {
                $level = $level > 0 ? $level-1 : 0;
            }
            $res .= str_repeat("    ", $level).$s."\n";
            if (preg_match("~[\{\(]$~", $s)) {
                $level++;
            }
        }
        return $res;
    }
    
    
    
    protected $templates = array();
    
    protected $_code_context = 'text';
    
    protected function tokenCodeToCode($token) {
        return $token->getProp('value');
    }
    
    protected function tokenHelpToCode($token) {
        $code = "<?php\n";
        $code .= "echo \$this->getHelp();\n";
        $code .= "?>";
        return $code;
    }
    
    protected function tokenCallToCode(Token $token) {
        $each = $token->getProp('each');
        if ($each) {
            $each_token = Token::create('{each}');
            $each_token->setProp('select', $each);
            
            $item = '$'.$this->varialize($each).'_item';
            $each_token->setProp('as', $item);
            $c_with = $token->getProp('with');
            $token->setProp('with', $item.($c_with ? ', '.$c_with : ''));
            $token->setProp('each', '');
            $each_token->addChild($token);
            return $this->tokenEachToCode($each_token);
        }
        $code = "<?php\n";
        $tpl_name = $token->getProp('id');
        // not a plain name
        if (!preg_match("~^[a-z0-9_\.\:]+$~", $tpl_name)) {
            $tpl_name = self::parseExpression($tpl_name);
        } else {
            if (!preg_match("~\:~", $tpl_name)) {
                $tpl_name = $this->_template_set_name.":".$tpl_name;
            }
            $tpl_name = '"'.$tpl_name.'"';
        }
        $tpl = '$tpl_'.$this->varialize($tpl_name);
        $code .= $tpl.' = fx::template('.$tpl_name.");\n";
        $inherit = $token->getProp('apply') ? 'true' : 'false';
        $code .= $tpl.'->setParent($this, '.$inherit.");\n";
        $call_children = $token->getChildren();
        /*
         * Converted:
         * {call id="wrap"}<div>Something</div>{/call}
         * like this:
         * {call id="wrap"}{var id="content"}<div>Something</div>{/var}{/call}
         */
        $has_content_param = false;
        foreach ($call_children as $call_child) {
            if ($call_child->name == 'code' && $call_child->isEmpty()) {
                continue;
            }
            if ($call_child->name != 'var') {
                $has_content_param = true;
                break;
            }
        }
        if ($has_content_param) {
            $token->clearChildren();
            $var_token = new Token('var', 'single', array('id' => 'content'));
            foreach ($call_children as $call_child) { 
                $var_token->addChild($call_child);
            }
            $token->addChild($var_token);
        }
        $with_expr = $token->getProp('with');
        if ($with_expr) {
            $ep = new ExpressionParser();
            $with_expr = $ep->parseWith($with_expr);
        }
        $switch_context = is_array($with_expr) && isset($with_expr['$']);
        if ($switch_context) {
            $code .= '$this->pushContext('.$this->parseExpression($with_expr['$']).");\n";
        }
        $code .= $tpl."->pushContext(array(), array('transparent' => false));\n";
        if (is_array($with_expr)) {
            foreach ($with_expr as $alias => $var) {
                if ($alias == '$') {
                    continue;
                }
                $code .= $tpl."->setVar(".
                    "'".trim($alias, '$')."', ".
                    $this->parseExpression($var).");\n";
            }
        }
        foreach ($token->getChildren() as $param_var_token) {
            // internal call only handle var
            if ($param_var_token->name != 'var') {
                continue;
            }
            $value_to_set = 'null';
            if ($param_var_token->hasChildren()) {
                // pass the inner html code
                $code .= "ob_start();\n";
                $code .= $this->childrenToCode($param_var_token);
                $code .= "\n";
                $value_to_set = 'ob_get_clean()';
            } elseif ( ($select_att = $param_var_token->getProp('select') ) ) {
                // pass the result of executing the php code
                $value_to_set = self::parseExpression($select_att);
            }
            $code .= $tpl."->setVar(".
                "'".$param_var_token->getProp('id')."', ".
                $value_to_set.");\n";
        }
        
        if ($switch_context) {
            $code .= "\$this->popContext();\n";
            $code .= $tpl."->pushContext(".$this->parseExpression($with_expr['$']).");\n";
        }
        $code .= 'echo '.$tpl."->render();\n";
        $code .= "\n?>";
        return $code;
    }
    
    public function parseExpression($str) {
        static $expression_parser = null;
        // todo: need verify name $expession_parser
        if ($expression_parser === null) {
            $expression_parser = new ExpressionParser();
            $expression_parser->local_vars []= '_is_admin';
        }
        return $expression_parser->compile($expression_parser->parse($str));
    }
    
    protected function applyModifiers($display_var, $modifiers, $token) {
        if (!$modifiers || count($modifiers) == 0) {
            return '';
        }
        $token_type = $token->getProp('type');
        $code = '';
        foreach ($modifiers as $mod) {
            $mod_callback = $mod['name'];
            
            if ($mod['is_template']) {
                $call_token = new Token('call', 'single', array('id' => $mod['name'], 'apply' => true));
                if (isset($mod['with'])) {
                    $call_token->setProp('with', $mod['with']);
                }
            }
            
            if ($mod['is_each'] && $mod['is_template']) {
                $c_with = $call_token->getProp('with');
                $call_token->setProp('with', "`".$display_var.'`_item'.($c_with ? ', '.$c_with : ''));
                $each_token = new Token('each', 'single', array('select' => "`".$display_var."`"));
                $each_token->addChild($call_token);
                $code = "ob_start();\n?>";
                $code .= $this->tokenEachToCode($each_token);
                $code .= "<?php\n".$display_var." = ob_get_clean();\n";
                continue;
            }
            
            if ($mod["is_each"]){ 
                $display_var_item = $display_var."_item";
                $code .= 'foreach ('.$display_var.' as &'.$display_var_item.") {\n";
            } else {
                $display_var_item = $display_var;
            }
                
            if (empty($mod_callback)) {
                if ($token_type) {
                    $mod_callback = $token_type == 'image' ? 'fx::image' : 'fx::date';
                    $mod_callback .= '(';
                } else {
                    $token->_need_type = true;
                    $mod_callback = 'call_user_func(';
                    $mod_callback .= '($var_type == "image" ? "fx::image" : ';
                    $mod_callback .= '($var_type == "datetime" ? "fx::date" : "fx::cb")), ';
                }
            } elseif ($mod['is_template']) {
                $code .= "ob_start();\n?>";
                $c_with = $call_token->getProp('with');
                $call_token->setProp('with', "`".$display_var.'`'.($c_with ? ', '.$c_with : ''));
                $call_token->setProp('apply', true);
                $code .= $this->tokenCallToCode($call_token);
                $code .= "<?php\n".$display_var_item. " = ob_get_clean();\n";
            } else {
                $mod_callback .= '(';
            }
            if (!$mod['is_template']) {
                $args = array();
                $self_used = false;
                foreach ($mod['args'] as $arg) {
                    if ($arg == 'self') {
                        $args []= $display_var_item;
                        $self_used = true;
                    } else {
                        $args []= self::parseExpression($arg);
                    }
                }
                if (!$self_used) {
                    array_unshift($args, $display_var_item);
                }
                $code .= $display_var_item.' = '. $mod_callback.join(', ', $args).");\n";
            }
            if ($mod['is_each']) {
                $code .= "}\n";
            }
        }
        return $code;
    }
    
    protected function makeFileCheck($var, $use_stub = false) {
        
        $code = $var . ' = trim('.$var.");\n";
        //$code .= "if (!preg_match(\"~^###fxf\d+~\", ".$var.")) {\n";
        $code .= "\nif (".$var." && !preg_match('~^(https?://|/)~', ".$var.")) {\n";
        $code .= $var . '= $template_dir.'.$var.";\n";
        $code .= "}\n";
        
        $code .= 'if (!'.$var.' || ( !preg_match("~^https?://~", '.$var.') && !file_exists(fx::path()->toAbs(preg_replace("~\?.+$~", "", '.$var.'))) )) {'."\n";
        if ($use_stub) {
            $stub_image = fx::path()->http('floxim', '/Admin/style/images/no.png');
            $code .= $var . "= \$_is_admin ? '".$stub_image."' : '';\n";
        } else {
            $code .= $var . "= '';\n";
        }
        $code .= "}\n";
        //$code .= "}\n";
        return $code;
    }
    
    protected function tokenVarToCode(Token $token) {
        $code = "<?php\n";
        // parse var expression and store token 
        // to create correct expression for get_var_meta()
        $ep = new ExpressionParser();
        $expr_token = $ep->parse('$'.$token->getProp('id'));
        $expr = $ep->compile($expr_token);
        $var_token = $expr_token->last_child;
        
        $modifiers = $token->getProp('modifiers');
        $token->setProp('modifiers', null);
        $token_is_visual = $token->getProp('var_type') == 'visual';
        
        $token_type = $token->getProp('type');
        // analyze default value to get token type and wysiwyg linebreaks mode
        if (
            !$token_type || 
            ($token_type == 'html' && !$token->getProp('linebreaks'))
        ) {
            $linebreaks = $token_is_visual;
            foreach ($token->getChildren() as $child) {
                $child_source = $child->getProp('value');
                if (!$token_type && preg_match("~<[a-z]+.*?>~i", $child_source)) {
                    $token_type = 'html';
                }
                if (preg_match("~<p.*?>~i", $child_source)) {
                    $linebreaks = false;
                }
            }
            if (!$token_type) {
                $token_type = 'string';
            } else {
                $token->setProp('type', $token_type);
            }
            if ($linebreaks || $token_is_visual) {
                $token->setProp('linebreaks', $linebreaks);
            }
        }
        
        // e.g. "name" or "image_".$this->v('id')
        $var_id = preg_replace('~^\$this->v\(~', '', preg_replace("~\)$~", '', $expr));
        
        $has_default = $token->getProp('default') || count($token->getChildren()) > 0;
        
        // if var has default value or there are some modifiers
        // store real value for editing
        $real_val_defined = false;
        $var_chunk = $this->varialize($var_id);
        $token_is_file = ($token_type == 'image' || $token_type == 'file');
        
        if ($modifiers || $has_default || $token->getProp('inatt')) {
            $real_val_var = '$'.$var_chunk.'_real_val';
            
            $code .= $real_val_var . ' = '.$expr.";\n";
            
            if ($token_is_file) {
                $code .= $this->makeFileCheck($real_val_var, !$has_default);
            }
            
            if ($modifiers || $has_default) {
                $display_val_var = '$'.$var_chunk.'_display_val';
                $code .= $display_val_var . ' = '.$real_val_var.";\n";
            } else {
                $display_val_var = $real_val_var;
            }
            $expr = $display_val_var;
            $real_val_defined = true;
        }
        
        $var_meta_expr = $this->getVarMetaExpression($token, $var_token, $ep);
        
        if ($has_default) {
            $code .= "\nif (is_null(".$real_val_var.") || ".$real_val_var." == '') {\n";
            
            if (!($default = $token->getProp('default')) ) {
                // ~= src="{%img}{$img /}{/%}" --> src="{%img}{$img type="image" /}{/%}
                $token_def_children = $token->getNonEmptyChildren();
                if (count($token_def_children) == 1 && $token_def_children[0]->name == 'var') {
                    $def_child = $token_def_children[0];
                    if (!$def_child->getProp('type')) {
                        $def_child->setProp('type', $token_type);
                    }
                }
                $has_complex_tokens = false;
                $default_parts = array();
                foreach ($token_def_children as $def_child) {
                    if ($def_child->name != 'code') {
                        $has_complex_tokens = true;
                        break;
                    }
                    $def_child_code = $def_child->getProp('value');
                    if (preg_match("~<\?(php|=)~", $def_child_code)) {
                        $has_complex_tokens = true;
                        break;
                    }
                    $default_parts []= '"'.addslashes($def_child_code).'"';
                }
                if ($has_complex_tokens) {
                    $code .= "\tob_start();\n";
                    if (!$token_is_visual) {
                        $code .= '$var_has_meta = count('.$var_meta_expr.");\n";
                        $code .= "if (\$var_has_meta) {\n";
                    }
                    $code .= '$'.$var_chunk.'_was_admin = $_is_admin;'."\n";
                    $code .= '$_is_admin = false;'."\n";
                    if (!$token_is_visual) {
                        $code .= "}\n";
                    }
                    $code .= "\t".$this->childrenToCode($token);
                    if (!$token_is_visual) {
                        $code .= "if (\$var_has_meta) {\n";
                    }
                    $code .= '$_is_admin = $'.$var_chunk.'_was_admin;'."\n";
                    if (!$token_is_visual) {
                        $code .= "}\n";
                    }
                    $default = "ob_get_clean()";
                } else {
                    $default = join(".", $default_parts);
                }
            }
            if ($real_val_defined) {
                $code .= "\n".$display_val_var.' = '.$default.";\n";
                if ($token_is_file) {
                    $code .= $this->makeFileCheck($display_val_var, true);
                }
                if ($token_is_visual) {
                    $code .= "\n".'$this->setVar('.$var_id.',  '.$display_val_var.");\n";
                }
            } elseif ($token_is_visual) {
                $code .= "\n".'$this->setVar('.$var_id.',  '.$default.");\n";
            }
            $code .= "}\n";
        }
        
        
        
        
        if ($modifiers) {
            
            $modifiers_code = $this->applyModifiers($display_val_var, $modifiers, $token);
            if ($token->_need_type) {
                $code .= '$var_meta = '.$var_meta_expr.";\n";
                $code .= '$var_type = $var_meta["type"]'.";\n";
                $var_meta_defined = true;
            }
            $code .= $modifiers_code;
        }
        if ($token->getProp('editable') == 'false') {
            $code .= 'echo  '.$expr.";\n";
        } else {
            $code .= 'echo !$_is_admin ? '.$expr.' : $this->printVar('."\n";
            $code .= $expr;
            $code .= ", \n";
            $meta_parts = array();
            if (!$token_is_visual) {
                $meta_parts []= $var_meta_defined ? '$var_meta' : $var_meta_expr;
            }
            $token_props = $token->getAllProps();


            $tp_parts = array();

            foreach ($token_props as $tp => $tpval) {
                if (!$token_is_visual && in_array($tp, array('id', 'var_type'))) {
                    continue;
                }
                $token_prop_entry = "'".$tp."' => ";
                if ($tp == 'id') {
                    $token_prop_entry .= $var_id;
                } elseif (preg_match("~^\`.+\`$~s", $tpval)) {
                    $token_prop_entry .= trim($tpval, '`');
                } else {
                    $token_prop_entry .= "'".addslashes($tpval)."'";
                }
                $tp_parts[]= $token_prop_entry;
            }
            if (count($tp_parts) > 0) {
                $meta_parts []= "array(".join(", ", $tp_parts).")";
            }
            $meta_parts []= '$_is_wrapper_meta';

            if ($token->getProp('editable') == 'false') {
                $meta_parts []= 'array("editable"=>false)';
            }
            if ($real_val_defined) {
                $meta_parts []= 'array("real_value" => '.$real_val_var.')';
            }
            $code .= 'array_merge('.join(", ", $meta_parts).')';
            $code .= "\n);\n";
        }
        $code .= "?>";
        return $code;
    }
    
    protected function getVarMetaExpression($token, $var_token, $ep) {
        // Expression to get var meta
        $var_meta_expr = '$this->getVarMeta(';
        // if var is smth like $item['parent']['url'], 
        // it should be get_var_meta('url', fx::dig( $this->v('item'), 'parent'))
        if ($var_token->last_child) {
            if ($var_token->last_child->type == ExpressionParser::T_ARR) {
                $last_index = $var_token->popChild();
                $tale = $ep->compile($last_index).', ';
                $tale .= $ep->compile($var_token).')';
                $var_meta_expr .= $tale;
            } else {
                $var_meta_expr .= ')';
            }
        } elseif ($var_token->context_offset !== null) {
            $prop_name = array_pop($var_token->name);
            $var_meta_expr .= '"'.$prop_name.'", '.$ep->compile($var_token);
            $var_meta_expr .= ')';
        } else {
            $var_meta_expr .= '"'.$token->getProp('id').'")';
        }
        return $var_meta_expr;
    }
    
    protected function varialize($var) {
        //static $counter;
        //return 'v'.$counter++;
        return preg_replace("~^_+|_+$~", '', 
                preg_replace(
            '~[^a-z0-9_]+~', '_', 
            preg_replace('~(?:\$this\->v|fx\:\:dig)~', '', $var)
        ));
    }
    
    protected function tokenWithEachToCode(Token $token) {
        $expr = self::parseExpression($token->getProp('select'));
        $arr_id = '$'.$this->varialize($expr).'_items';
        
        $each_token = new Token('each', 'double', array(
            'select' => '`'.$arr_id.'`',
            'as' => $token->getProp('as'),
            'key' => $token->getProp('key'),
            'check_traversable' => 'false'
        ));
        
        
        if ( ($separator = $this->findSeparator($token)) ) {
            $each_token->addChild($separator);
        }
        
        
        $code .= "<?php\n";
        $code .= $arr_id.' = '.$expr.";\n";
        $code .= "if (".$arr_id." && (is_array(".$arr_id.") || ".$arr_id." instanceof Traversable) && count(".$arr_id.")) {\n?>";
        
        $items = array();
        
        foreach ($token->children as $child) {
            if ($child->name == 'item') {
                $items[]= $child;
            }
        }
        
        usort($items, function($a, $b) {
            $ta = $a->getProp('test') ? 1 : 0;
            $tb = $b->getProp('test') ? 1 : 0;
            return $tb - $ta;
        });
        
        $all_subroot = true;
        $target_token = $each_token;
        foreach ($items as $num => $item) {
            $test = $item->getProp('test');
            $item_subroot = $item->getProp('subroot');
            if (!$item_subroot || $item_subroot == 'false') {
                $all_subroot = false;
            }
            if (!$test) {
                $test = 'true';
            }
            $cond_token = new Token(
                $num == 0 ? 'if' : 'elseif', 
                'double', 
                array('test' => $test)
            );
            foreach ($item->getChildren() as $item_child) {
                $cond_token->addChild($item_child);
            }
            $target_token->addChild($cond_token);
            $target_token = $cond_token;
        }
        if ($all_subroot) {
            $each_token->setProp('subroot', 'true');
        }
        
        $in_items = false;
        $each_added = false;
        foreach ($token->children as $child) {
            if ($child->name == 'item' && !$in_items) {
                $in_items = true;
            }
            if (!$in_items) {
                $code .= $this->getTokenCode($child, $token);
                continue;
            }
            if (!$each_added) {
                $code .= $this->getTokenCode($each_token, $token);
                $each_added = true;
            }
            if ($child->name == 'item' || $child->isEmpty()) {
                continue;
            }
            $in_items = false;
            $code .= $this->getTokenCode($child, $token);
        }
        
        $code .= "<?php\n}\n?>";
        return $code;
    }
    /*
     * Find & remove separator from token children and return it
     * separator is special token {separator}..{/separator} or var {%separator}..{/%}
     */
    protected function findSeparator(Token $token) {
        $separator = null;
        if ( ($separator_text = $token->getProp('separator')) ) {
            $separator = new Token('separator', 'double', array());
            $separator_text = new Token('code', 'single', array('value' => $separator_text));
            $separator->addChild($separator_text);
            return $separator;
        }
        foreach ($token->getChildren() as $each_child_num => $each_child) {
            if (
                $each_child->name == 'separator' || 
                ($each_child->name == 'var' && $each_child->getProp('id') == 'separator')
            ) {
                if ($each_child->name == 'var') {
                    $separator = new Token('separator', 'double', array());
                    $separator->addChild($each_child);
                } else {
                    $separator = $each_child;
                }
                
                $token->setChild(null, $each_child_num);
                break;
            }
        }
        return $separator;
    }
    
    protected function getItemCode($token, $item_alias, $counter_id = null, $arr_id = 'array()') {
        $code = '';
        $is_entity = '$'.$item_alias."_is_entity";
        $code .=  $is_entity ." = \$".$item_alias." instanceof \\Floxim\\Floxim\\Template\\Entity;\n";
        $is_complex = 'is_array($'.$item_alias.') || is_object($'.$item_alias.')';
        $code .= '$this->pushContext( '.$is_complex.' ? $'.$item_alias." : array());\n";
        
        $meta_test = "\tif (\$_is_admin && ".$is_entity." ) {\n";
        $code .= $meta_test;
        $code .= "\t\tob_start();\n";
        $code .= "\t}\n";
        $code .= $this->childrenToCode($token)."\n";
        $code .= $meta_test;
        $code .= "\t\techo \$".$item_alias."->addTemplateRecordMeta(".
                    "ob_get_clean(), ".
                    $arr_id.", ".
                    ($counter_id ? '$'.$counter_id." - 1, " : '$this->v("position") - 1, ').
                    ($token->getProp('subroot') ? 'true' : 'false').
                ");\n";
        $code .= "\t}\n";
        $code .= "\$this->popContext();\n";
        return $code;
    }

    protected function tokenEachToCode(Token $token) {
        $code = "<?php\n";
        $select = $token->getProp('select');
        if (empty($select)) {
            $select = '$.items';
        }
        $arr_id = self::parseExpression($select);
        
        
        $loop_alias = 'null';
        $item_alias = $token->getProp('as');
        
        if (!preg_match('~^\$[a-z0-9_]+$~', $arr_id)) {
            $arr_hash_name = '$arr_'.$this->varialize($arr_id);
            $code .= $arr_hash_name .'= '.$arr_id.";\n";
            $arr_id = $arr_hash_name;
        }
        
        if (!$item_alias) {
            $item_alias = $arr_id.'_item';
        } else {
            $loop_alias = '"'.preg_replace('~^\$~', '', $item_alias).'"';
        }
        $item_alias = preg_replace('~^\$~', '', $item_alias);
        
        // key for loop
        $loop_key = 'null';
        
        $item_key = $token->getProp('key');
        if (!$item_key) {
            $item_key = $item_alias.'_key';
        } else {
            $item_key = preg_replace('~^\$~', '', $item_key);
            $loop_key = '"'.$item_key.'"';
        }
        
        $separator = $this->findSeparator($token);
        $check_traversable = $token->getProp('check_traversable') !== 'false';
        if ($check_traversable) {
            $code .= "if (is_array(".$arr_id.") || ".$arr_id." instanceof Traversable) {\n";
        }
        // add-in-place settings
        
        $code .= 'if ($_is_admin && '.$arr_id.' instanceof \\Floxim\\Floxim\\System\\Collection && isset('.$arr_id.'->finder)';
        $code .= ' && $this->getMode("add") != "false" ';
        $code .= ' && '.$arr_id.'->finder instanceof \\Floxim\\Main\\Content\\Finder) {'."\n";
        $code .= $arr_id.'->finder->createAdderPlaceholder('.$arr_id.');'."\n";
        $code .= "}\n";
        
        $loop_id = '$'.$item_alias.'_loop';
        $code .=  $loop_id.' = new \\Floxim\\Floxim\\Template\\Loop('.$arr_id.', '.$loop_key.', '.$loop_alias.");\n";
        //$code .= '$this->context_stack[]= '.$loop_id.";\n";
        $code .= "\$this->pushContext(".$loop_id.", array('transparent' => true));\n";
        $code .= "\nforeach (".$arr_id." as \$".$item_key." => \$".$item_alias.") {\n";
        $code .= $loop_id."->move();\n";
        // get code for step with scope & meta
        $code .= $this->getItemCode($token, $item_alias, $counter_id, $arr_id);
        
        if ($separator) {
            $code .= 'if (!'.$loop_id.'->isLast()) {'."\n";
            $code .= $this->childrenToCode($separator);
            $code .= "\n}\n";
        }
        $code .= "}\n"; // close foreach
        //$code .= 'array_pop($this->context_stack);'."\n"; // pop loop object
        $code .= "\$this->popContext();\n";
        if ($check_traversable) {
            $code .= "}\n";  // close if
        }
        $code .= "\n?>";
        return $code;
    }
    
    protected function tokenWithToCode($token) {
        $code = "<?php\n";
        $expr = self::parseExpression($token->getProp('select'));
        $item_name = $this->varialize($expr).'_with_item';
        $code .= '$'.$item_name.' = '.$expr.";\n";
        $code .= "if ($".$item_name.") {\n";
        $code .= $this->getItemCode($token, $item_name);
        $code .= "}\n";
        $code .= "?>";
        return $code;
    }

    protected function tokenTemplateToCode($token) {
        $this->registerTemplate($token);
    }
    
    protected function tokenSetToCode($token) {
        $var = $token->getProp('var');
        $value = self::parseExpression($token->getProp('value'));
        $is_default = $token->getProp('default');
        $code .= "<?php\n";
        
        if (preg_match("~\.~",$var)) {
            $parts = explode('.', $var, 2);
            $var_name = trim($parts[0], '$');
            $var_path = $parts[1];
            $code .= 'fx::digSet($this->v("'.$var_name.'"), "'.$var_path.'", '.$value.");\n";
            $code .= "?>\n";
            return $code;
        }
        
        $var = $this->varialize($var);
        
        if ($is_default) {
            $code .= "if (is_null(\$this->v('".$var."','local'))) {\n";
        }
        
        $code .= '$this->setVar("'.$var.'", '.$value.');'."\n";
        if ($is_default) {
            $code .= "}\n";
        }
        $code .= "?>\n";
        return $code;
    }

    protected static function getAreaLocalTemplates($area_token) {
        $templates = array();
        $traverse = function(Token $node) use (&$templates, &$traverse) {
            foreach ($node->getChildren() as $child) {
                if ($child->name === 'area') {
                    continue;
                }
                if ($child->name === 'template') {
                   $templates[]= $child->getProp('id'); 
                }
                $traverse($child);
            }
        };
        $traverse($area_token);
        return $templates;
    }
    
    protected function tokenAreaToCode($token) {
        //$token_props = var_export($token->get_all_props(),1);
        $token_props_parts = array();
        $local_templates = self::getAreaLocalTemplates($token);
        $parsed_props = array();
        foreach ($token->getAllProps() as $tp => $tpval) {
            $c_part = "'".$tp."' => ";
            if ($tp === 'suit') {
                $res_suit = Suitable::compileAreaSuitProp(
                    $tpval, 
                    $local_templates, 
                    $this->_template_set_name
                );
                $c_val = "'".$res_suit."'";
            } elseif (preg_match("~^`.+`$~s", $tpval)) {
                $c_val = trim($tpval, '`');
            } elseif (preg_match('~\$~', $tpval)) {
                $c_val = $this->parseExpression($tpval);
            } else {
                $c_val = "'".addslashes($tpval)."'";
            }
            $parsed_props[$tp] = $c_val;
            $token_props_parts []= $c_part.$c_val;
        }
        $token_props = 'array('.join(", ", $token_props_parts).')';
        $res = '';
        $res = '<?php $this->pushContext(array("area_infoblocks" => fx::page()->getAreaInfoblocks('.$parsed_props['id'].")));\n?>";
        $render_called = false;
        foreach ($token->getChildren() as $child_num => $child) {
            if ($child->name == 'template') {
                $child->setProp('area', $token->getProp('id'));
                if (!$render_called) {
                    if ($child_num > 0) {
                        $res = 
                            "<?php\n".
                            'if ($_is_admin) {'."\n".
                            'echo $this->renderArea('.$token_props.', \'marker\');'."\n".
                            '}'."\n?>\n".
                            $res.
                            '<?php echo $this->renderArea('.$token_props.', \'data\');?>';
                    } else {
                        $res .= '<?php echo $this->renderArea('.$token_props.');?>';
                    }
                    $render_called = true;
                }
                $this->registerTemplate($child);
            } else {
                $res .= $this->getTokenCode($child, $token);
            }
        }
        if (!$render_called) {
            $res = '<?php echo $this->renderArea('.$token_props.');?>'.$res;
        }
        $res .= "<?php \$this->popContext();\n?>";
        return $res;
    }
    
    protected function tokenIfToCode($token) {
        $code  = "<?php\n";
        $cond = $token->getProp('test');
        $cond = trim($cond);
        $cond = self::parseExpression($cond);
        if (empty($cond)) {
            $cond = 'false';
        }
        $code .= 'if ('.$cond.") {\n";
        $code .= $this->childrenToCode($token)."\n";
        $code .= "} ";
        $code .= $this->elsesToCode($token);
        $code .= "\n?>";
        return $code;
    }
    
    protected function tokenElseToCode($token) {
        $code .= " else {\n";
        $code .= $this->childrenToCode($token)."\n";
        $code .= "}\n";
        return $code;
    }


    protected function tokenElseifToCode($token) {
        $cond = $token->getProp('test');
        $cond = trim($cond);
        $cond = self::parseExpression($cond);
        
        $code = ' elseif ('.$cond.') {'."\n";
        $code .= $this->childrenToCode($token)."\n";
        $code .= "} ";
        $code .= $this->elsesToCode($token);
        $code .= "\n";
        return $code;
    }
    
    protected function tokenJsToCode($token) {
        return $this->tokenHeadfileToCode($token, 'js');
    }
    
    protected function tokenCssToCode($token) {
        return $this->tokenHeadfileToCode($token, 'css');
    }
    
    protected function tokenHeadfileToCode($token, $type) {
        $code .= "<?php\n";
        foreach ($token->getChildren() as $set) {
            $set = preg_split("~[\n]~", $set->getProp('value'));
            foreach ($set as $file) {
                $file = trim($file);
                if (empty($file)) {
                    continue;
                }
                $res_string = '';
                $alias = null;
                if (preg_match('~\sas\s~', $file)) {
                    $file_parts = explode(" as ", $file);
                    $file = trim($file_parts[0]);
                    $alias = trim($file_parts[1]);
                }
                // constant
                if (preg_match("~^[A-Z0-9_]+$~", $file)) {
                    $res_string = $file;
                } elseif (!preg_match("~^(/|https?://)~", $file)) {
                    $res_string = '$template_dir."'.$file.'"';
                } else {
                    $res_string = '"'.$file.'"';
                }
                if ($alias) {
                    $code .= "if (!fx::page()->hasFileAlias('".$alias."', '".$type."')) {\n";
                }
                $code .= 'fx::page()->add'.fx::util()->underscoreToCamel($type).'File('.$res_string.");\n";
                if ($alias) {
                    $code .= "fx::page()->hasFileAlias('".$alias."', '".$type."', true);\n";
                    $code .= "}\n";
                }
            }
        }
        $code .= "\n?>";
        return $code;
    }

    protected function getTokenCode($token, $parent) {
        $method_name = 'token'.fx::util()->underscoreToCamel($token->name).'ToCode';
        if (method_exists($this, $method_name)) {
            return call_user_func(array($this, $method_name), $token, $parent);
        }
        return '';
    }

    protected function childrenToCode(Token $token) {
        $parts = array();
        foreach ($token->getChildren() as $child) {
            if ($child->name !== 'elseif' && $child->name !== 'else') {
                $parts []= $this->getTokenCode($child, $token);
            }
        }
        if (count($parts) == 0) {
            return '';
        }
        $code = '?>'.join("", $parts)."<?php ";
        return $code;
    }
    
    protected function elsesToCode($token) {
        $code = '';
        foreach ($token->getChildren() as $child) {
            if ($child->name == 'elseif' || $child->name == 'else') {
                $code .= $this->getTokenCode($child, $token);
            }
        }
        return $code;
    }
    
    protected function makeTemplateCode($tpl_props) {
        $tpl_id = $tpl_props['id'];
        
        $children_code = $tpl_props['_code'];
        
        $code = "public function tpl_".$tpl_id.'() {'."\n";
        
        $template_path = str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', $this->_current_source_file);
        $template_path = str_replace('\\', '/', $template_path);
        $template_dir = preg_replace("~/[^/]+$~", '', $template_path).'/';
        
        $code .= "fx::env()->addCurrentTemplate(\$this);\n";
        
        $code .= "\$template_dir = '".$template_dir."';\n";
        $code .= "\$_is_admin = \$this->isAdmin();\n";
        $code .= 'if ($_is_admin) {'."\n";
        $code .= "\$_is_wrapper_meta = \$this->isWrapper() ? array('template_is_wrapper' => 1) : array();\n";
        $code .= "}\n";
        
        if (isset($tpl_props['_variants'])) {
            $tpl_variants = $tpl_props['_variants'];
            foreach ($tpl_variants as &$v) {
                $t = $v['_token'];
                if ( !($prior = $t->getProp('priority')) ){ 
                    $prior = $t->getProp('test') ? 0.5 : 0;
                }
                $v['_priority'] = $prior;
            }
            
            @ usort($tpl_variants, function($a, $b) {
                $ap = $a['_priority'];
                $bp = $b['_priority'];
                $diff = round( ($bp - $ap) * 100);
                return $diff;
            });
            
            foreach ($tpl_variants as $var_num => $var) {
                $token = $var['_token'];
                $test = $token->getProp('test');
                if (!$test) {
                    $test = 'true';
                }
                $code .= $var_num == 0 ? 'if' : 'elseif';
                $code .= '('.self::parseExpression($test).") {\n";
                $is_subroot = $token->getProp('subroot') ? 'true' : 'false';
                $code .= "\t\$this->is_subroot = ".($is_subroot).";\n";
                $code .= $var['_code']."\n"; //$this->_children_to_code($token)."\n";
                $code .= "}\n";
            }
        } else {
            $token = $tpl_props['_token'];
            $is_subroot = $token->getProp('subroot') ? 'true' : 'false';
            $code .= "\t\$this->is_subroot = ".($is_subroot).";\n";
            $code .= $children_code;
        }
        $code .= "fx::env()->popCurrentTemplate();\n";
        $code .= "\n}\n";
        return $code;
    }
    
    protected function getTemplateProps(Token $token) {
        $tpl_props = array(
            'id' => $token->getProp('id'),
            'file' => $this->_current_source_file
        );
        if ( ($offset = $token->getProp('offset')) ) {
            $tpl_props['offset'] = $offset;
        }
        if ( ($size = $token->getProp('size'))) {
            $tpl_props['size'] = $size;
        }
        if ( ($suit=  $token->getProp('suit'))) {
            $tpl_props['suit'] = $suit;
        }
        if (  ($area_id = $token->getProp('area'))) {
            $tpl_props['area'] = $area_id;
        }
        
        if ( !($name = $token->getProp('name'))) {
            $name = $token->getProp('id');
        }
        
        $tpl_props['full_id'] = $this->_template_set_name.':'.$tpl_props['id'];
        
        $of = $token->getProp('of');
        // todo: psr0 need fix
        $of_map = array(
            'menu' => 'section:list',
            'wrapper' => 'wrapper:show', // fake widget!
            'blockset' => 'blockset:show',
            'grid' => 'grid:show',
            'block' => 'block:show' // no implementation yet
        );

        if ($of and $of != 'false') {
            $of_parts = explode(',', $of);
            array_walk($of_parts, function(&$v) {
                $v=trim($v);
            });
            foreach($of_parts as $key => $value){
                if (isset($of_map[$value])) {
                    $value = $of_map[$value];
                }
                $of_parts[$key] = fx::getComponentFullName($value);
            }
            $of = join(',', $of_parts);
        } else {
            $of = false;
        }
        
        $tpl_props += array(
            'name' => $name,
            'of' => $of,
            '_token' => $token
        );
        return $tpl_props;
    }
    
    protected function registerTemplate(Token $token) {
        if ($token->name != 'template') {
            return;
        }
        $tpl_id = $token->getProp('id');
        
        $tpl_props = $this->getTemplateProps($token);
        $tpl_props['_code'] = $this->childrenToCode($token);
        
        if (isset($this->templates[$tpl_id])) {
            // this is the second template with the same name
            if (!isset($this->templates[$tpl_id]['_variants'])) {
                $first_tpl = $this->templates[$tpl_id];
                $this->templates[$tpl_id] = $first_tpl + array(
                    '_variants' => array($first_tpl)
                );
            }
            $this->templates[$tpl_id]['_variants'][]= $tpl_props;
        } else {
            $this->templates[$tpl_id] = $tpl_props;
        }
    }
    
    /*
     * Passes through the upper level, starting the collection of templates deep
     */
    protected function collectTemplates($root) {
        foreach ($root->getChildren() as $template_file_token) {
            $this->_current_source_file = $template_file_token->getProp('source');
            foreach ($template_file_token->getChildren() as $template_token) {
                $this->registerTemplate($template_token);
            }
        }
    }
    
    protected function  makeCode(Token $tree, $class_name) {
        // Name of the class/template group
        $this->_template_set_name = $tree->getProp('name');
        if ( ($ct = $tree->getProp('controller_type'))) {
            $this->_controller_type = $ct;
        }
        if ( ($cn = $tree->getProp('controller_name'))) {
            $this->_controller_name = $cn;
        }
        $this->collectTemplates($tree);
        ob_start();
        echo "<?php\n";
        // todo: psr0 need fix
        echo 'class '.$class_name." extends \\Floxim\\Floxim\\Template\\Template {\n";
        
        $tpl_var = array();
        foreach ( $this->templates as $tpl_props) {
            echo $this->makeTemplateCode($tpl_props);
            unset($tpl_props['_token']);
            unset($tpl_props['_variants']);
            unset($tpl_props['_code']);
            $tpl_var []= $tpl_props;
        }
        echo 'protected $_templates = '.var_export($tpl_var,1).";\n";
        echo "}";
        $code = ob_get_clean();
        return $code;
    }
    
    /*
     * From comments: http://php.net/manual/en/function.php-check-syntax.php
     */
    public static function isPhpSyntaxCorrect($code) {
        $braces = 0;
        $inString = 0;
        $code = preg_replace("~^\s*\<\?(php)?~", '', $code);
        $code = preg_replace("~\?>\s*$~", '', $code);
        // First of all, we need to know if braces are correctly balanced.
        // This is not trivial due to variable interpolation which
        // occurs in heredoc, backticked and double quoted strings
        $all_tokens = token_get_all('<?php '.$code);
        foreach ($all_tokens as $token) {
            if (is_array($token)) {
                switch ($token[0])  {
                    case T_CURLY_OPEN:
                    case T_DOLLAR_OPEN_CURLY_BRACES:
                    case T_START_HEREDOC: ++$inString; break;
                    case T_END_HEREDOC:   --$inString; break;
                }
            } else if ($inString & 1) {
                switch ($token) {
                    case '`':
                    case '"': --$inString; break;
                }
            } else {
                switch ($token) {
                    case '`':
                    case '"': ++$inString; break;
                    case '{': ++$braces; break;
                    case '}':
                        if ($inString) {
                            --$inString;
                        } else {
                            --$braces;
                            if ($braces < 0) {
                                break 2;
                            }
                        }
                        break;
                }
            }
        }

        // Display parse error messages and use output buffering to catch them
        $prev_ini_log_errors = @ini_set('log_errors', false);
        $prev_ini_display_errors = @ini_set('display_errors', true);
        

        // If $braces is not zero, then we are sure that $code is broken.
        // We run it anyway in order to catch the error message and line number.

        // Else, if $braces are correctly balanced, then we can safely put
        // $code in a dead code sandbox to prevent its execution.
        // Note that without this sandbox, a function or class declaration inside
        // $code could throw a "Cannot redeclare" fatal error.

        $braces || $code = "if(0){{$code}\n}";
        
        ob_start();
        $eval_res = eval($code);
        
        if (false === $eval_res) {
            if ($braces) {
                $braces = PHP_INT_MAX;
            } else {
                // Get the maximum number of lines in $code to fix a border case
                false !== strpos($code, "\r") && $code = strtr(str_replace("\r\n", "\n", $code), "\r", "\n");
                $braces = substr_count($code, "\n");
            }

            $buffer_output = ob_get_clean();
            $buffer_output = strip_tags($buffer_output);
            
            // Get the error message and line number
            if (preg_match("'syntax error, (.+) in .+ on line (\d+)$'s", $buffer_output, $error_data)) {
                $error_data[2] = (int) $error_data[2];
                $error_data = $error_data[2] <= $braces
                    ? array_slice($error_data,1)
                    : array('unexpected $end' . substr($error_data[1], 14), $braces);
            }
            $error_data['raw_output'] = $buffer_output;
            $result = array('syntax error', $error_data);
        } else {
            ob_end_clean();
            $result = true;
        }

        @ini_set('display_errors', $prev_ini_display_errors);
        @ini_set('log_errors', $prev_ini_log_errors);
        return $result;
    }
}
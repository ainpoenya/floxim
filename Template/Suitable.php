<?php

namespace Floxim\Floxim\Template;

use Floxim\Floxim\System;
use Floxim\Floxim\System\Fx as fx;

class Suitable
{

    public static function unsuit($layout_id = null, $site_id = null)
    {
        if (is_null($site_id)) {
            $site_id = fx::env('site')->get('id');
        }
        if (is_null($layout_id)) {
            $layout_id = fx::data('site', $site_id)->get('layout_id');
        }
        if (!is_numeric($layout_id)) {
            $layout = fx::data('layout')->whereOr(
                array('keyword', '%'.$layout_id.'%', 'like'),
                array('keyword', '%'.$layout_id.'%', 'like')
            )->one();
            if (!$layout) {
                return;
            }
            $layout_id = $layout['id'];
        }
        $infoblocks_query = fx::data('infoblock')
            ->where('site_id', $site_id)
            ->onlyWith(
                'visuals',
                function ($q) use ($layout_id) {
                    $q->where('layout_id', $layout_id);
                }
            );


        $infoblocks = $infoblocks_query->all();
        $infoblocks->apply(function ($ib) {
            $visual = $ib['visuals']->first();
            if ($visual) {
                $visual->delete();
            }
        });
    }

    public function suit(System\Collection $infoblocks, $layout_id)
    {
        $layout = fx::data('layout', $layout_id);
        $layout_ib = null;
        $stub_ibs = new System\Collection();
        // Collect all Infoblox without the visual part
        // Find the InfoBlock-layout
        foreach ($infoblocks as $ib) {
            if ($ib->getVisual()->get('is_stub')) {
                $stub_ibs[] = $ib;
            }
            if ($ib->isLayout()) {
                $layout_ib = $ib;
            }
        }
        $layout_rate = array();
        $all_visual = fx::data('infoblock_visual')->getForInfoblocks($stub_ibs, false);

        foreach ($all_visual as $c_vis) {
            $c_layout_id = $c_vis['layout_id'];
            $infoblocks->
                findOne('id', $c_vis['infoblock_id'])->
                setVisual($c_vis, $c_layout_id);
            if (!isset($layout_rate[$c_layout_id])) {
                $layout_rate[$c_layout_id] = 0;
            }
            // count how many visual blocks are defined for each layout
            // later we should sort this to find correct "original" layout made by human
            $layout_rate[$c_layout_id]++;
        }
        
        //$source_layout_id = $c_layout_id;
        $avail_layout_ids = array_keys($layout_rate);
        // temp: use first
        // $source_layout_id = $avail_layout_ids[0];
        $source_layout_id = end($avail_layout_ids);

        if (!$layout_ib) {
            $layout_ib = fx::router('front')->getLayoutInfoblock(fx::env('page'));
        }

        $area_map = array();
        
        if ($layout_ib->getVisual()->get('is_stub') || !$layout_ib->getTemplate()) {
            $this->adjustLayoutVisual($layout_ib, $layout_id, $source_layout_id);
            $layout_visual = $layout_ib->getVisual();
            $area_map = $layout_visual['area_map'];
        } else {
            $layout_visual = $layout_ib->getVisual();
            $old_layout_template = $layout_ib->getPropInherited('visual.template', $source_layout_id);
            if ($old_layout_template) {
                $old_areas = fx::template($old_layout_template)->getAreas();
                $new_areas = fx::template($layout_visual['template'])->getAreas();
                
                //$tplv['real_areas'] = $test_layout_tpl->getAreas();
                $area_map = $this->mapAreas($old_areas, $new_areas);
                $area_map = $area_map['map'];
            }
        }
        
        
        $layout_template_name = $layout_ib->getPropInherited('visual.template');
        $layout_template = fx::template($layout_template_name);
        // seems to be second call of ::getAreas(), can be cached or reused
        $c_areas = $layout_template->getAreas();
        
        $c_wrappers = array();
        
        foreach ($infoblocks as $ib) {
            $ib_visual = $ib->getVisual($layout_id);
            if (!$ib_visual['is_stub']) {
                continue;
            }
            
            $old_area = $ib->getPropInherited('visual.area', $source_layout_id);
            // Suit record infoblock to the area where list infoblock is placed
            if ($ib->getPropInherited('action') == 'record') {
                $content_type = $ib->getPropInherited('controller');
                $content_ibs = fx::data('infoblock')
                                ->where('page_id', $ib['page_id'])
                                ->getContentInfoblocks($content_type);
                if (count($content_ibs)) {
                    $list_ib = $content_ibs->first();
                    $list_ib_vis = $list_ib->getVisual($layout_id);
                    if ($list_ib_vis && $list_ib_vis['area']) {
                        $ib_visual['area'] = $list_ib_vis['area'];
                    }
                }
            }
            if (!$ib_visual['area']) {
                if ($old_area && isset($area_map[$old_area])) {
                    $ib_visual['area'] = $area_map[$old_area];
                    $ib_visual['priority'] = $ib->getPropInherited('visual.priority', $source_layout_id);

                } // preserve areas generated by grid widget
                elseif (preg_match("~^grid_~", $old_area)) {
                    $ib_visual['area'] = $old_area;
                }
            }
            
            $ib_controller = fx::controller(
                $ib->getPropInherited('controller'),
                $ib->getPropInherited('params'),
                $ib->getPropInherited('action')
            );
            
            $area_meta = isset($c_areas[$ib_visual['area']]) ? $c_areas[$ib_visual['area']] : null;

            $controller_templates = $ib_controller->getAvailableTemplates($layout['keyword'], $area_meta);
            
            
            $old_template = $ib->getPropInherited('visual.template', $source_layout_id);
            
            $used_template_props = null;
            foreach ($controller_templates as $c_tpl) {
                if ($c_tpl['full_id'] === $old_template) {
                    $ib_visual['template'] = $c_tpl['full_id'];
                    $used_template_props = $c_tpl;
                    break;
                }
            }
            if (!$ib_visual['template']) {
                $that = $this;
                $old_template_id = preg_replace("~^.*?:~", '', $old_template);
                $controller_templates = fx::collection($controller_templates);
                
                $controller_templates->sort(
                    function(&$tpl) use ($that, $old_template_id) {
                        $res = $that->compareNames($tpl['id'], $old_template_id);
                        $tpl['name_match'] = $res;
                        return 1/($res+1);
                    }
                );
                $res_template = $controller_templates->first();
                $ib_visual['template'] = $res_template['full_id'];
                $used_template_props = $res_template;
            }
            
            if (!$ib_visual['area']) {
                $block_size = self::getSize($used_template_props['size']);
                $c_area = null;
                $c_area_count = 0;
                foreach ($c_areas as $ca) {
                    $area_size = self::getSize($ca['size']);
                    $area_count = self::checkSizes($block_size, $area_size);
                    if ($area_count >= $c_area_count) {
                        $c_area_count = $area_count;
                        $c_area = $ca['id'];
                    }
                }
                $ib_visual['area'] = $c_area;
            }

            $old_wrapper = $ib->getPropInherited('visual.wrapper', $source_layout_id);
            if ($old_wrapper){
                if (!isset($c_wrappers[$c_area])) {
                    $c_wrappers[$c_area] = self::getAvailableWrappers($layout_template, $area_meta);
                }
                $old_wrapper_id = preg_replace("~^.+\:~", '', $old_wrapper);
                $avail_wrappers = $c_wrappers[$c_area];
                if (count($avail_wrappers)) {
                    $new_wrapper = fx::collection($avail_wrappers)->sort(
                        function($w) use ($old_wrapper_id) {
                            return 1/(1 + Suitable::compareNames($w['name'], $old_wrapper_id));
                        }
                    )->first();
                    $ib_visual['wrapper'] = $new_wrapper['full_id'];
                    $ib_visual['wrapper_visual'] = $ib->getPropInherited('visual.wrapper_visual', $source_layout_id);
                }
            }
            
            //if ($old_wrapper && $area_meta) {
            if ($area_meta) {
                $area_suit = self::parseAreaSuitProp(isset($area_meta['suit']) ? $area_meta['suit'] : null);
                if ($area_suit['default_wrapper']) {
                    $ib_visual['wrapper'] = $area_suit['default_wrapper'][0];
                    $ib_visual['wrapper_visual'] = $ib->getPropInherited('visual.wrapper_visual', $source_layout_id);
                }
            }

            unset($ib_visual['is_stub']);
            $ib_visual->save();
        }
    }
    
    public static function getAvailableWrappers($layout_tpl, $area_meta = null)
    {
        
        $area_suit = self::parseAreaSuitProp(isset($area_meta['suit']) ? $area_meta['suit'] : '');
        $force_wrapper = $area_suit['force_wrapper'];
        $area_size = self::getSize($area_meta['size']);

        $wrappers = array();
        
        $template_variants = $layout_tpl->getTemplateVariants();
        
        $replace = array();
        
        foreach ($template_variants as $tplv) {
            $full_id = $tplv['full_id'];
            if (!isset($tplv['of']['floxim.layout.wrapper:show'])) {
                continue;
            }
            if (!isset($tplv['suit'])) {
                $tplv['suit'] = '';
            }
            if ($tplv['suit'] == 'local' && $area_meta['id'] != $tplv['area']) {
                continue;
            }
            if ($force_wrapper && !in_array($tplv['full_id'], $force_wrapper)) {
                continue;
            }
            if (is_string($tplv['suit']) && $tplv['suit']) {
                $tplv_suit = preg_split("~\,\s*~", $tplv['suit']);
                if (in_array('local', $tplv_suit)) {
                    $tplv_suit []= $tplv['area'];
                }
                if (!in_array($area_meta['id'], $tplv_suit)) {
                    continue;
                }
            }

            $size_ok = true;
            if ($area_size && isset($tplv['size'])) {
                $size = self::getSize($tplv['size']);
                $size_rate = self::checkSizes($size, $area_size);
                if (!$size_rate) {
                    $size_ok = false;
                }
            }
            if ($size_ok) {
                $wrappers[$full_id] = $tplv;
                if ($tplv['is_preset_of'] && $tplv['replace_original']) {
                    $replace []= $tplv['is_preset_of'];
                }
            }
        }
        foreach ($replace as $replaced_id) {
            unset($wrappers[$replaced_id]);
        }
        return $wrappers;
    }

    protected function adjustLayoutVisual($layout_ib, $layout_id, $source_layout_id)
    {
        $is_root_layout = (bool)$layout_ib['parent_infoblock_id'];
        if ($is_root_layout && $source_layout_id) {
            $root_layout_ib = $layout_ib->getRootInfoblock();
            if ($root_layout_ib->getVisual($layout_id)->get('is_stub')) {
                $this->adjustLayoutVisual($root_layout_ib, $layout_id, $source_layout_id);
            }
        }
        
        $layout = fx::data('layout', $layout_id);

        $layout_tpl = fx::template('theme.' . $layout['keyword']);
        $template_variants = $layout_tpl->getTemplateVariants();

        $source_template_params = null;
        
        if ($source_layout_id) {
            $source_template = $layout_ib->getPropInherited('visual.template', $source_layout_id);
            if (!$is_root_layout) {
                $source_template_params = $layout_ib->getPropInherited('visual.template_visual', $source_layout_id);
            }
            $old_template = fx::template($source_template);
            if ($old_template) {
                $old_areas = $old_template->getAreas();
            } else {
                $old_areas = array();
            }
            
            $c_relevance = 0;
            $c_variant = null;
            foreach ($template_variants as $tplv) {
                if ($tplv['of'] !== 'floxim.component.layout:show' && $tplv['id'] !== '_layout_body') {
                    continue;
                }
                $test_layout_tpl = fx::template($tplv['full_id']);
                $tplv['real_areas'] = $test_layout_tpl->getAreas();
                $map = $this->mapAreas($old_areas, $tplv['real_areas']);
                
                if (!$map) {
                    continue;
                }
                if ($map['relevance'] > $c_relevance) {
                    $c_relevance = $map['relevance'];
                    $c_variant = $map + array(
                        'full_id' => $tplv['full_id'],
                        'areas'   => $tplv['real_areas']
                    );
                }
            }
        }

        if (!$source_layout_id || !$c_variant) {
            foreach ($template_variants as $tplv) {
                if ($tplv['of'] == 'layout:show') {
                    $c_variant = $tplv;
                    break;
                }
            }
            if (!$c_variant) {
                $c_variant = array('full_id' => 'theme.' . $layout['keyword'] . ':_layout_body');
            }
        }

        $layout_vis = $layout_ib->getVisual();
        $layout_vis['template'] = $c_variant['full_id'];
        if ($source_template_params) {
            $layout_vis['template_visual'] = $source_template_params;
        }
        if ($c_variant['areas']) {
            $layout_vis['areas'] = $c_variant['areas'];
            $layout_vis['area_map'] = $c_variant['map'];
        }
        unset($layout_vis['is_stub']);
        $layout_vis->save();
    }

    /*
     * Compares two sets of fields
     * Considers the relevance of size, title and employment
     * Returns an array with the keys in the map and relevance
     */
    protected function mapAreas($old_set, $new_set)
    {
        $total_relevance = 0;
        $old_pos = 0;
        foreach ($old_set as $old_area_id => &$old_area) {
            $old_size = $this->_getSize($old_area);
            $c_match = false;
            $c_match_index = 1;
            $old_pos++;
            $new_pos = 0;
            
            foreach ($new_set as $new_area_id => $new_area) {
                $new_pos++;
                $new_size = $this->_getSize($new_area);
                $area_match = 0;

                // if one of the areas arbitrary width - existent, 1
                if ($new_size['width'] == 'any' || $old_size['width'] == 'any') {
                    $area_match += 1;
                } // if the width is the same as - good, 2
                elseif ($new_size['width'] == $old_size['width']) {
                    $area_match += 2;
                } // if no width is matched, no good
                else {
                    continue;
                }

                // if one of the areas of arbitrary height - existent, 1
                if ($new_size['height'] == 'any' || $old_size['height'] == 'any') {
                    $area_match += 1;
                } // if the height voityla - good, 2
                elseif ($new_size['height'] == $old_size['height']) {
                    $area_match += 2;
                } // new area - high, old - low, you can replace, 1
                elseif ($new_size['height'] == 'high') {
                    $area_match += 1;
                } // a new low, old - high, no good
                else {
                    continue;
                }

                // if area names have something common
                $area_match += $this->compareNames($old_area['id'], $new_area['id']);

                // if the field is already another: -2
                if (isset($new_area['used']) && $new_area['used']) {
                    $area_match -= 1;
                }
                
                $offset_diff = abs($old_pos/count($old_set) - $new_pos/count($new_set))*2;
                $area_match -= $offset_diff;
                
                // if the current index is larger than the previous - remember
                if ($area_match > $c_match_index) {
                    $c_match = $new_area_id;
                    $c_match_index = $area_match;
                }
            }
            if ($c_match_index == 0) {
                return false;
            }
            if ($c_match) {
                $old_area['analog'] = $c_match;
                $old_area['relevance'] = $c_match_index;
                $new_set[$c_match]['used'] = true;
                $total_relevance += $c_match_index;
            }
        }
        // for each unused lower the score 2
        foreach ($new_set as $new_area) {
            if (!isset($new_area['used'])) {
                $total_relevance -= 2;
            }
        }
        $map = array();
        foreach ($old_set as $old_set_item) {
            $map[$old_set_item['id']] = isset($old_set_item['analog']) ? $old_set_item['analog'] : null;
        }
        $res = array('relevance' => $total_relevance, 'map' => $map);
        return $res;
    }

    public static function getSize($size)
    {
        $res = array('width' => 'any', 'height' => 'any');
        if (empty($size)) {
            return $res;
        }
        $width = null;
        $height = null;
        if (preg_match('~wide|narrow~', $size, $width)) {
            $res['width'] = $width[0];
        }
        if (preg_match('~high|low~', $size, $height)) {
            $res['height'] = $height[0];
        }
        return $res;
    }

    public static function checkSizes($block, $area)
    {
        if ($area['width'] === 'narrow' && $block['width'] === 'wide') {
            return 0;
        }
        if ($area['height'] === 'low' && $block['height'] === 'high') {
            return 0;
        }
        $n = 1;
        if ($block['height'] !== 'any' && $area['height'] === $block['height']) {
            $n++;
        } elseif ($block['height'] === 'any' && $area['height'] === 'high') {
            $n += 0.5;
        }
        if ($block['width'] !== 'any' && $area['width'] === $block['width']) {
            $n++;
        } elseif ($block['width'] === 'any' && $area['width'] === 'wide') {
            $n++;
        }
        return $n;
    }
    
    protected function guessSizeByName($name)
    {
        if (preg_match("~main|content~", $name)) {
            return array('width' => 'wide', 'height' => 'high');
        }
        if (preg_match("~side|col~", $name)) {
            return array('width' => 'narrow', 'height' => 'high');
        }
        
        if (preg_match("~head|top|foot|bottom~", $name)) {
            return array('height' => 'low', 'width' => 'wide');
        }
    }

    protected function _getSize($block)
    {
        $res = array('width' => 'any', 'height' => 'any');
        if (!isset($block['size'])) {
            if (!isset($block['id'])) {
                return $res;
            }
            $guessed = $this->guessSizeByName($block['id']);
            if (!$guessed) {
                return $res;
            }
            return $guessed;
        }
        if (preg_match('~wide|narrow~', $block['size'], $width)) {
            $res['width'] = $width[0];
        }
        if (preg_match('~high|low~', $block['size'], $height)) {
            $res['height'] = $height[0];
        }
        return $res;
    }

    // suit props that should contain templates
    protected static $tpl_suit_props = array('force_wrapper', 'force_template', 'default_wrapper');

    public static function parseAreaSuitProp($suit)
    {
        $res = array();
        $suit = explode(";", $suit);
        foreach ($suit as $v) {
            $v = trim($v);
            if (empty($v)) {
                continue;
            }
            $v = explode(':', $v, 2);
            if (count($v) == 1) {
                $res[trim($v[0])] = true;
            } else {
                $p = trim($v[0]);
                if (empty($p)) {
                    continue;
                }
                $res[$p] = array();
                foreach (explode(",", $v[1]) as $rv) {
                    $res[$p][] = trim($rv);
                }
                if (count($res[$p]) == 1) {
                    $res[$p] = $res[$p][0];
                }
            }
        }
        foreach (self::$tpl_suit_props as $prop) {
            if (!isset($res[$prop])) {
                $res[$prop] = false;
            } elseif (!is_array($res[$prop])) {
                $res[$prop] = array($res[$prop]);
            }
        }
        return $res;
    }

    public static function compileAreaSuitProp($suit, $local_templates, $set_name)
    {
        $suit = self::parseAreaSuitProp($suit);
        foreach (self::$tpl_suit_props as $prop) {
            if (!$suit[$prop]) {
                continue;
            }
            $local_key = array_keys($suit[$prop], 'local');
            if ($local_key) {
                $suit[$prop] = array_merge($suit[$prop], $local_templates);
                unset($suit[$local_key[0]]);
            }
            foreach ($suit[$prop] as &$tpl_name) {
                $tpl_name = trim($tpl_name, ':');
                if (!strstr($tpl_name, ':')) {
                    $tpl_name = $set_name . ':' . $tpl_name;
                }
            }
        }
        $res_suit = '';
        foreach ($suit as $p => $v) {
            if (is_bool($v) && !$v) {
                continue;
            }
            $res_suit .= $p;
            if (!is_bool($v)) {
                $res_suit .= ':';
                $res_suit .= is_array($v) ? join(',', $v) : $v;
            }
            $res_suit .= '; ';
        }
        return $res_suit;
    }

    /**
     * Compare names by trigrams
     * @param string $s1
     * @param string $s2
     * @return int count common trigrams
     */
    public static function compareNames($s1, $s2)
    {
        
        if ($s1 === $s2) {
            return mb_strlen($s1)*100;
        }
        $getNgrams = function ($word, $n = 3) {
            $ngrams = array();
            for ($i = 0; $i < mb_strlen($word); $i++) {
                if ($i > ($n - 2)) {
                    $ng = '';
                    for ($j = $n - 1; $j >= 0; $j--) {
                        $ng .= $word[$i - $j];
                    }
                    $ngrams[] = $ng;
                }
            }
            $ngrams = array_unique($ngrams);
            return $ngrams;
        };
        $n1 = $getNgrams($s1);
        $n2 = $getNgrams($s2);
        $rate = 0;
        foreach ($n1 as $g) {
            if (in_array($g, $n2)) {
                $rate++;
            }
        }
        return $rate;
    }
}
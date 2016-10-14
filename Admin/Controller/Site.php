<?php

namespace Floxim\Floxim\Admin\Controller;

use Floxim\Floxim\System\Fx as fx;

class Site extends Admin
{

    public function all()
    {
        $sites = fx::data('site')->all();

        $list = array(
            'type'     => 'list',
            'filter'   => true,
            'sortable' => true
        );
        $list['labels'] = array(
            'name'     => fx::alang('Site name', 'system'),
            'domain'   => fx::alang('Domain', 'system'),
            'language' => fx::alang('Language', 'system'),
            'theme'    => fx::alang('Theme')
        );

        $list['values'] = array();
        $list['entity'] = 'site';
        foreach ($sites as $v) {
            $theme_name = $v['theme']['name'];
            $r = array(
                'id'       => $v['id'],
                'domain'   => '<a href="http://'.$v['domain'].'" target="_blank">'.$v['domain'].'</a>',
                'name'     => array(
                    'url'  => 'site.settings(' . $v['id'] . ')',
                    'name' => $v['name']
                ),
                'language' => $v['language'],
                'theme'    => $theme_name
            );
            $list['values'][] = $r;
        }

        $this->response->addField($list);

        $this->response->addButtons(array(
            array(
                'key'   => 'add',
                'title' => fx::alang('Add new site', 'system'),
                'url'   => '#admin.administrate.site.add'
            ),
            'delete'
        ));
        $this->response->breadcrumb->addItem(fx::alang('Sites', 'system'));
        $this->response->submenu->setMenu('site');
    }

    public function add()
    {
        $fields = $this->getFields(fx::data('site')->create());
        $fields[] = $this->ui->hidden('action', 'add_save');
        $fields[] = $this->ui->hidden('entity', 'site');

        $this->response->addFields($fields);
        $this->response->dialog->setTitle(fx::alang('Create a new site', 'system'));
        $this->response->breadcrumb->addItem(fx::alang('Sites', 'system'), '#admin.administrate.site.all');
        $this->response->breadcrumb->addItem(fx::alang('Add new site', 'system'));
        $this->response->addFormButton('save');
        $this->response->submenu->setMenu('site');
    }

    protected function getStyleVariantId($input)
    {
        $style_variant_id = $input['style_variant'];
        if ($style_variant_id === '__new') {
            $style_variant_name = $input['new_variant_name'];
            $style_name = 'theme.'.fx::env('layout')->get('keyword');
            $new_variant = fx::data('style_variant')->create(
                array(
                    'name' => $style_variant_name,
                    'style' => $style_name,
                    'less_vars' => fx::env()->getLayoutStyleVariant()->getLessVars()
                )
            );
            $new_variant->save();
            $style_variant_id = $new_variant['id'];
        }
        return $style_variant_id;
    }

    public function addSave($input)
    {

        $result = array();
        
        $palette = fx::data('palette')->create(
            array(
                'params' => array()
            )
        );
        
        $palette->save();
        
        $theme = fx::data('theme')->create(
            array(
                'palette_id' => $palette['id'],
                'layout' => 'floxim.basic'
            )
        );
        
        $theme->save();
        
        $site = fx::data('site')->create(array(
            'name'      => $input['name'],
            'domain'    => $input['domain'],
            'theme_id' => $theme['id'],
            //'style_variant_id' => $this->getStyleVariantId($input),
            'mirrors'   => $input['mirrors'],
            'language'  => $input['language'],
            'checked'   => 1
        ));




        if (!$site->validate()) {
            $result['status'] = 'error';
            $result['errors'] = $site->getValidateErrors();
            return $result;
        }

        $site->save();

        $index_page = fx::data('floxim.main.page')->create(array(
            'name'    => fx::alang('Cover Page', 'system'),
            'url'     => '/',
            'site_id' => $site['id']
        ))->save();

        $error_page = fx::data('floxim.main.page')->create(array(
            'name'      => fx::alang('Page not found', 'system'),
            'url'       => '/404',
            'site_id'   => $site['id'],
            'parent_id' => $index_page['id']
        ))->save();

        $site['error_page_id'] = $error_page['id'];
        $site['index_page_id'] = $index_page['id'];

        $layout_ib = fx::data('infoblock')->create(array(
            'controller' => 'layout',
            'action'     => 'show',
            'name'       => 'Layout',
            'site_id'    => $site['id'],
            'scope_type' => 'all_pages'
        ))->save();
        
        $layout_vis = fx::data('infoblock_visual')->create(
            array(
                'theme_id' => $theme['id'],
                'infoblock_id' => $layout_ib['id'],
                'template' => 'theme.floxim.basic:_layout_body'
            )
        );
                
        $layout_vis->save();
        
        $site->save();
        fx::input()->setCookie('fx_target_location', '/floxim/#admin.site.all');
        $result = array(
            'status' => 'ok',
            'reload' => '/~ajax/floxim.user.user:cross_site_auth_form'
        );
        return $result;
    }

    protected function setLayout($section, $site)
    {
        $titles = array(
            'map'      => fx::alang('Site map', 'system'),
            'settings' => fx::alang('Settings', 'system'),
            'design'   => fx::alang('Design', 'system')
        );
        $this->response->breadcrumb->addItem(fx::alang('Sites', 'system'), '#admin.site.all');
        $this->response->breadcrumb->addItem($site['name'], '#admin.site.settings(' . $site['id'] . ')');
        $this->response->breadcrumb->addItem($titles[$section]);
        $this->response->submenu->setMenu('site-' . $site['id'])->setSubactive('site' . $section . '-' . $site['id']);
    }

    /**
     * Get fields for website create/edit form
     *
     * @param type fx_site $site
     *
     * @return array
     */
    protected function getFields($site)
    {
        $main_fields = array();
        $main_fields[] = $this->ui->input('name', fx::alang('Site name', 'system'), $site['name']);
        $main_fields[] = $this->ui->input('domain', fx::alang('Domain', 'system'), $site['domain']);
        $main_fields[] = array(
            'name'  => 'mirrors',
            'label' => fx::alang('Aliases', 'system'),
            'value' => $site['mirrors'],
            'type'  => 'text'
        );

        $languages = fx::data('lang')->all()->getValues('lang_code', 'lang_code');
        $main_fields[] = array(
            'name'   => 'language',
            'type'   => 'select',
            'values' => $languages,
            'value'  => $site['language'],
            'label'  => fx::alang('Language', 'system')
        );
        //$main_fields = array_merge($main_fields, Layout::getThemeFields($site));
        return $main_fields;
    }

    public function settings($input)
    {
        $site_id = isset($input['id']) ? $input['id'] : isset($input['params'][0]) ? $input['params'][0] : null;
        $site = fx::data('site', $site_id);

        $main_fields = $this->getFields($site);

        $this->response->addFields($main_fields);

        $fields = array();
        $fields[] = $this->ui->hidden('entity', 'site');
        $fields[] = $this->ui->hidden('action', 'settings');
        $fields[] = $this->ui->hidden('posting');
        $fields [] = $this->ui->hidden('id', $site['id']);
        $this->response->addFields($fields);
        $this->response->addFormButton('save');
        $this->setLayout('settings', $site);
    }

    public function settingsSave($input)
    {

        $site = fx::data('site')->getById($input['id']);
        $result = array(
            'status' => 'ok',
            'reload' => '#admin.site.all'
        );
        $params = array(
            'name',
            'domain',
            'mirrors',
            'language',
            'robots',
            'layout_id',
            'style_variant_id',
            'index_page_id',
            'error_page_id',
            'offline_text'
        );

        $input['style_variant_id'] = $this->getStyleVariantId($input);

        foreach ($params as $v) {
            if (isset($input[$v])) {
                $site[$v] = $input[$v];
            }
        }

        $site->save();
        return $result;
    }
}
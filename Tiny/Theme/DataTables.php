<?php
/**
 * Created by hisune.com.
 * User: hi@hisune.com
 * Date: 2014/9/30 0030
 * Time: 上午 10:07
 *
 * Theme builder之dataTable
 */
namespace Tiny\Theme;

use Tiny as tiny;

class DataTables implements tiny\ThemeBuilder
{
    public $setting = array(); // 配置数组
    public $id = 'data-tables'; // 唯一id
    public $html = ''; // html内容
    private $js = ''; // 临时js
    private $sortHtml = array( // 排序html
        'asc' => '<i class="glyphicon pointer glyphicon-arrow-up sort-active" onclick="dataTablesSort($(this))"><input class="data-tables-submit" type="hidden" name="_sort[%field%]" value="asc"></i>',
        'desc' => '<i class="glyphicon pointer glyphicon-arrow-down sort-active" onclick="dataTablesSort($(this))"><input class="data-tables-submit" type="hidden" name="_sort[%field%]" value="desc"></i>',
        'default' => '<i class="glyphicon pointer glyphicon-sort sort-default" onclick="dataTablesSort($(this))"><input class="data-tables-submit" disabled type="hidden" name="_sort[%field%]" value=""></i>',
    );
    public $post = array(); // 查询数据的post内容
    public $url = '';

    public function build()
    {
        if($this->setting && isset($this->setting['column'])){
            $request = new tiny\Request;
            $this->url = $request::url();
            if($request->isPost()){ // 提交数据
                $this->post = $request->post();
                $this->_renderPost();
            }else { // 显示表单
                // 组装html
                $this->_renderHtml();
                // 输出html
                $this->_show();
            }
        }else
            echo 'dataTables 配置有误';
    }

    private function _renderHtml()
    {
        // id
        if(isset($this->setting['id']) && $this->setting['id'])
            $this->id = $this->setting['id'];
        else
            $this->id = 'data-tables-' . mt_rand(1, 250);
        // dataTables顶部
        $this->html = tiny\Html::tag(
            'div',
            $this->_filter(),
            array(
                'class' => 'panel-heading',
            )
        );
        // dataTable内容table
        $this->html .= tiny\Html::tag(
            'div',
            $this->_table(),
            array(
                'class' => 'panel-body'
            )
        );
        // dataTable底部
        $this->html .= tiny\Html::tag(
            'div',
            '<div class="row"><div class="col-sm-12"><div class="pull-left" id="' . $this->id . '-page-left"></div>
                <div class="pull-right" id="' . $this->id . '-page-right"></div></div></div>',
            array(
                'class' => 'panel-footer'
            )
        );
        // dataTables包裹层
        $this->html = tiny\Html::tag(
            'div',
            $this->html,
            array(
                'class' => 'panel panel-default',
                'id' => $this->id,
            )
        );
        // form包裹层
        $this->html = tiny\Html::tag(
            'form',
            $this->html,
            array(
                'id' => $this->id . '-form',
                'onsubmit' => 'return false;',
            )
        );
        // js
        $this->html .= tiny\Html::tag(
            'div',
            '',
            array(
                'id' => $this->id . '-js',
            )
        );
    }

    private function _renderPost()
    {
        // 1. 判断model，helper是否存在
        $model =
            isset($this->setting['model']) ?
                ucfirst(\Tiny\Config::$application) . '\\Model\\' . ucfirst($this->setting['model']) :
                str_replace('\\Controller\\', '\\Model\\', \Tiny\Request::$controller);
        $helper = str_replace('\\Controller\\', '\\Helper\\', \Tiny\Request::$controller);
        if(!class_exists($model))
            \Tiny\Error::echoJson(0, '模型不存在: ' . $model);
        if(!class_exists($helper))
            \Tiny\Error::echoJson(0, '辅助类不存在: ' . $helper);

        // 2. 执行helper前置函数
        if(method_exists($helper, 'dataTablesPostBefore'))
            $helper::dataTablesPostBefore($this->post);

        $model = new $model;

        // 3. 整理join参数
        if(isset($this->setting['join'])){
            $model->alias($this->setting['join']['main']);
            foreach($this->setting['join']['on'] as $v){
                $model->join($v['join'], $v['type']);
            }
        }

        // 4. 整理field, where, order参数
        $field = '';
        $whereStr = isset($this->setting['default']['filter']) ? $this->setting['default']['filter'] : '1=1';
        $whereBind = array();
        foreach($this->setting['column'] as $column){
            // export
            if(isset($this->post['_export']) && $this->post['_export'] && $this->_displayColumn($column))
                $exportTitle[] = $column['title'];
            if(isset($column['name'])){
                // order，需要放前面，不然后面会continue
                if(isset($this->post['_sort'][$column['name']]))
                    $order = $column['name'];
                // field
                if(isset($column['alias']))
                    $field .= "{$column['name']} as {$column['alias']},";
                else
                    $field .= "{$column['name']} as '{$column['name']}',"; // 这里防止join并且未设置字段alias时处理数据无法得到对应值
                // where
                if(isset($column['filter'])){
                    // 执行自定义过滤函数
                    if(isset($column['filter']['call'])){
                        $call = 'dataTablesFilter' . ucfirst($column['filter']['call']);
                        if(!method_exists($helper, $call))
                            \Tiny\Error::echoJson(0, '辅助类中的过滤函数不存在: ' . $call);
                        $helper::$call($this->post, $callStr, $callBind);
                        if($callStr && $callBind){
                            $whereStr .= ' and ' . $callStr;
                            $whereBind = array_merge($whereBind, $callBind);
                        }
                    }else{ // 内置过滤
                        if(!isset($this->post['_filter'][$column['name']]) || $this->post['_filter'][$column['name']] === '')
                            continue;
                        switch ($column['filter']['type']) {
                            case 'date': // 默认的date为int类型
                                $whereStr .= " and({$column['name']} = ?)";
                                $whereBind = array_merge(
                                    $whereBind,
                                    array(strtotime($this->post['_filter'][$column['name']]))
                                );
                                break;
                            case 'date_range':
                                $dateRange = explode('~', $this->post['_filter'][$column['name']]);
                                $whereStr .= " and({$column['name']} >= ? and {$column['name']} <= ?)";
                                $whereBind = array_merge(
                                    $whereBind,
                                    array(strtotime($dateRange['0']), strtotime($dateRange['1']))
                                );
                                break;
                            case 'range':
                                if($this->post['_filter'][$column['name']]['0'] !== '' && $this->post['_filter'][$column['name']]['1'] !== ''){
                                    $whereStr .= " and({$column['name']} >= ? and {$column['name']} <= ?)";
                                    $whereBind = array_merge(
                                        $whereBind,
                                        array($this->post['_filter'][$column['name']]['0'], $this->post['_filter'][$column['name']]['1'])
                                    );
                                }
                                break;
                            case 'input': // 只有input可进行like处理
                                if(isset($this->post['_like'][$column['name']])){
                                    $whereStr .= " and({$column['name']} like ?)";
                                    $whereBind = array_merge($whereBind, array('%' . $this->post['_filter'][$column['name']] . '%'));
                                }else{
                                    $whereStr .= " and({$column['name']} = ?)";
                                    $whereBind = array_merge($whereBind, array($this->post['_filter'][$column['name']]));
                                }
                                break;
                            default:
                                $whereStr .= " and({$column['name']} = ?)";
                                $whereBind = array_merge($whereBind, array($this->post['_filter'][$column['name']]));
                        }
                    }
                }
            }
        }
        if($field) $model->field(rtrim($field, ','));
        $model->where($whereStr, $whereBind);

        $options = $model->getOptions(); // count后会清楚options，这里需要先获取，后面重新赋值

        // 5. 其他默认配置参数（group，having）
        if(isset($this->setting['default']['group']) && $this->setting['default']['group'])
            $this->model->group($this->setting['default']['group']);
        if(isset($this->setting['default']['having']) && $this->setting['default']['having'])
            $this->model->having($this->setting['default']['having']);
        $msg['js'] = isset($this->setting['js']) ? $this->setting['js'] : '';

        // 6. 统计总记录数
        $msg['total'] = $model->count();
        if($msg['total'] == 0)
            \Tiny\Error::echoJson(2, '好像木有数据...');

        $model->setOptions($options);

        // 7. 整理order
        if(isset($order)){
            if(!in_array($this->post['_sort'][$order], array('asc', 'desc')))
                \Tiny\Error::echoJson(0, '排序类型错误: ' . $this->post['_sort'][$order]);
            $model->order($order . ' ' . $this->post['_sort'][$order]);
        }else{
            if(isset($this->setting['join'])){
                $model->order($this->setting['join']['main'] . '.' . $model->getKey() . ' desc');
            }else
                $model->order($model->getKey() . ' desc');
        }

        // 8. 整理limit
        if(isset($this->setting['page']) && $this->setting['page'] === false){ // 不分页

        }else{
            $msg['current'] = isset($this->post['_page']['current']) ? $this->post['_page']['current'] : 1;
            $msg['per'] =
                isset($this->post['_page']['per']) ?
                    $this->post['_page']['per'] :
                    (isset($this->setting['page']) ? $this->setting['page'] : 10);
            $msg['page'] = ceil($msg['total'] / $msg['per']);
            if($msg['current'] < 0)
                \Tiny\Error::echoJson(0, '分页参数错误: 当前页需大于0');
            if($msg['current'] > $msg['page'])
                $msg['current'] = $msg['page'];
            if($msg['per'] < 10)
                $msg['per'] = 10;
            $model->limit($msg['current'] * $msg['per'] - $msg['per'], $msg['per']);
        }

        // 9. find结果集
        $msg['rows'] = $model->find();
//        echo $model->getLastSql();

        // 10。处理结果集
        if($msg['rows']){
            $data = array();
            foreach($msg['rows'] as $row){
                $tmp = array();
                foreach($this->setting['column'] as $column){
                    if(!$this->_displayColumn($column)) continue;
                    if(isset($column['name'])){
                        // 真实字段名
                        $name = isset($column['alias']) ? $column['alias'] : $column['name'];
                        if(isset($column['call'])){
                            switch($column['call']){
                                case 'date':
                                    $tmp[] = $row->{$name} ? date('Y-m-d H:i:s', $row->{$name}) : '-';
                                    break;
                                case 'date_short':
                                    $tmp[] = $row->{$name} ? date('Y-m-d', $row->{$name}) : '-';
                                    break;
                                case 'enum':
                                    $tmp[] = isset($column['enum'][$row->{$name}]) ? $column['enum'][$row->{$name}] : '?(' . $row->{$name}. ')';
                                    break;
                                default:
                                    $tmp[] = $this->_callShowRender($column, $helper, $row);
                            }
                        }else
                            $tmp[] = is_null($row->{$name}) ? '' : $row->{$name};
                    }else
                        $tmp[] = $this->_callShowRender($column, $helper, $row);
                }
                $data[] = $tmp;
            }
            $msg['rows'] = $data;
        }


        // 11. 执行helper后置函数
        if(method_exists($helper, 'dataTablesPostAfter'))
            $helper::dataTablesPostAfter($msg);

        // 12. 是否是导出
        if(isset($this->post['_export']) && $this->post['_export'])
            tiny\Func::any2excel(array_merge(array($exportTitle), $data));
        // 13. 输出结果
        tiny\Error::echoJson(1, $msg);
    }

    private function _callShowRender($column, $helper, $row)
    {
        if(!isset($column['call']))
            \Tiny\Error::echoJson(0, '参数配置缺失call函数');
        $call = 'dataTablesShow' . ucfirst($column['call']);
        if(!method_exists($helper, $call))
            \Tiny\Error::echoJson(0, '辅助类中的输出函数不存在: ' . $call);
        return $helper::$call($row);
    }

    // 是否显示字段
    private function _displayColumn($column)
    {
        if(isset($column['display']) && !$column['display'])
            return false;
        else
            return true;
    }

    /**
     * 过滤panel的header内容
     */
    private function _filter()
    {
        $html = '';
        $search = false;
        foreach($this->setting['column'] as $column){
            if(isset($column['filter'])){
                $search = true;
                $html .= '<span style="display: inline-block; margin: 2px 12px 2px 0;">'; // 不换行
                $value = isset($column['filter']['value']) ? $column['filter']['value'] : '';
                switch($column['filter']['type']){
                    case 'hidden': // 可用来做默认过滤值，替代设置里面的default
                        $html .= "<input type='hidden' name='_filter[{$column['name']}]' class='data-tables-submit' value='{$value}'/>";
                        break;
                    case 'input': // input值过滤，可设置默认过滤值value 及 filter的title为placeholder，默认读column的title 及 width
                        $width = isset($column['filter']['width']) ? 'style="width: ' . $column['filter']['width'] . 'px;"' : 'style="width: 110px;"';
                        $title = isset($column['filter']['title']) ? $column['filter']['title'] : $column['title'];
                        $html .= "{$column['title']}: <input type='text' name='_filter[{$column['name']}]' class='form-control input-sm data-tables-submit' value='{$value}' placeholder='{$title}' {$width}>";
                        if(isset($column['filter']['like']) && $column['filter']['like'])
                            $html .= " <input type='checkbox' class='data-tables-submit pointer' name='_like[{$column['name']}]' value='true' title='是否使用模糊查询'/>";
                        break;
                    case 'select': // select单选值过滤，可设置value, option
                        // html
                        $class = str_replace('.', '', 'select-single-' . $column['name']); // 过滤join
                        $html .= "{$column['title']}: <select name='_filter[{$column['name']}]' class='data-tables-submit select-single {$class}'>";
                        $html .= '<option value="">- 请选择 -</option>';
                        if($column['filter']['option']):
                            foreach ($column['filter']['option'] as $k => $v) {
                                if($value == $k)
                                    $selected = 'selected';
                                else
                                    $selected = '';
                                $html .= "<option value='{$k}' {$selected}>{$v}</option>";
                            }
                        endif;
                        $html .= '</select>';
                        // js
                        $filter = isset($column['filter']['filter']) && $column['filter']['filter'] ? 'true' : 'false';
                        $height = isset($column['filter']['height']) ? $column['filter']['height'] : '400';
                        $this->js .= "$('#{$this->id} .{$class}').multiselect({checkboxName: '',enableFiltering: {$filter},maxHeight: {$height}});";
                        break;
                    case 'range':
                        $width = isset($column['filter']['width']) ? 'style="width: ' . $column['filter']['width'] . 'px;"' : 'style="width: 70px;"';
                        if($value)
                            $range = explode('~', $value);
                        else
                            $range['0'] = $range['1'] = '';
                        $html .= "{$column['title']}: <input type='text' name='_filter[{$column['name']}][0]' class='form-control input-sm data-tables-submit' value='{$range['0']}' placeholder='从' {$width}>~
                                  <input type='text' name='_filter[{$column['name']}][1]' class='form-control input-sm data-tables-submit' value='{$range['1']}' placeholder='到' {$width}>";
                        break;
                    case 'date_range':
                        if(isset($column['filter']['format'])){
                            $class = str_replace('.', '', 'date-range-' . $column['name']); // 过滤join
                            $this->js .= $this->_makeDateRangeJs($class, $column['filter']['format']);
                        }else{
                            $class = 'date-range-picker';
                            $this->js .= $this->_makeDateRangeJs($class, 'YYYY-MM-DD HH:mm');
                        }
                        $width = isset($column['filter']['width']) ? 'style="width: ' . $column['filter']['width'] . 'px;"' : 'style="width: 230px;"';
                        $title = isset($column['filter']['title']) ? $column['filter']['title'] : $column['title'];
                        $html .= "{$column['title']}: <input type='text' name='_filter[{$column['name']}]' class='form-control input-sm {$class} data-tables-submit' value='{$value}' placeholder='{$title}' {$width}>";
                        break;
                    case 'date':
                        if(isset($column['filter']['format'])){
                            $class = str_replace('.', '', 'date-time-' . $column['name']);
                            $this->js .= $this->_makeDateTimeJs($class, $column['filter']['format']);
                        }else{
                            $class = 'date-time-picker';
                            $this->js .= $this->_makeDateTimeJs($class, 'YYYY-MM-DD HH:mm');
                        }
                        $width = isset($column['filter']['width']) ? 'style="width: ' . $column['filter']['width'] . 'px;"' : 'style="width: 140px;"';
                        $title = isset($column['filter']['title']) ? $column['filter']['title'] : $column['title'];
                        $html .= "{$column['title']}: <input type='text' name='_filter[{$column['name']}]' class='form-control input-sm {$class} data-tables-submit' value='{$value}' placeholder='{$title}' {$width}>";
                        break;
                }
                $html .= '</span>';
            }
        }
        // 其他html
        if(isset($this->setting['title']) && is_string($this->setting['title']))
            $html .= $this->setting['title'];
        // 搜索与导出按钮
        if($search)
            $html .= '<button type="button" class="btn btn-primary btn-sm" style="margin-left: 12px;" onclick="dataTablesSubmit();">搜索</button>';
        if(isset($this->setting['export']) && $this->setting['export'])
            $html .= '<button type="button" class="btn btn-info btn-sm" style="margin-left: 12px;" onclick="dataTablesSubmit(true);">导出</button>';

        return $html;
    }

    /**
     * 表格头部和底部
     */
    private function _table()
    {
        $columnNum = 0; // 列数
        $head = '<thead><tr>';
        $foot = '<tfoot><tr>';
        foreach($this->setting['column'] as $column){
            if(!$this->_displayColumn($column)) continue;
            $columnNum++;
            // 排序
            if(isset($column['sort'])){
                if(isset($this->sortHtml[$column['sort']]))
                    $sort = str_replace('%field%', $column['name'], $this->sortHtml[$column['sort']]);
                else
                    $sort = str_replace('%field%', $column['name'], $this->sortHtml['default']);
            }else
                $sort = '';
            // tips
            $tips = isset($column['tips']) ? '<i class="glyphicon glyphicon-question-sign" data-toggle="tooltip" data-placement="top" data-original-title="'. $column['tips'] .'"></i>' : '';
            $head .= '<th>' . $column['title'] . $tips . $sort . '</th>';
            $foot .= '<th>' . $column['title'] . '</th>';
        }
        $head .= '</tr></thead>';
        $foot .= '</tr></tfoot>';

        $body = '<tbody id="' . $this->id . '-body" data-column="' . $columnNum . '">
            <tr>
                <td colspan="' . $columnNum . '">
                    <div class="progress progress-striped active" style=" margin:15px 50px;">
                        <div class="progress-bar" role="progressbar" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100" style="width: 100%;">
                        </div>
                    </div>
                </td>
            </tr>
            </tbody>';

        // 附加html
        $before = (isset($this->setting['before']) && is_string($this->setting['before'])) ? $this->setting['before'] : '';
        $after = (isset($this->setting['after']) && is_string($this->setting['after'])) ? $this->setting['after'] : '';

        return $before . '<table class="table table-striped table-hover">' . $head . $body . $foot . '</table>' . $after;
    }

    private function _show()
    {
        // 临时引入
//        echo '<script src="http://cdn.bootcss.com/jquery/2.1.1/jquery.min.js"></script>';
//        echo '<link href="http://cdn.bootcss.com/bootstrap/3.2.0/css/bootstrap.min.css" rel="stylesheet">';
//        echo '<link href="http://cdn.bootcss.com/bootstrap/3.2.0/css/bootstrap-theme.min.css" rel="stylesheet">';
//        echo '<script src="http://cdn.bootcss.com/bootstrap/3.2.0/js/bootstrap.min.js"></script>';
//        echo tiny\View::script('bootstrap/plugin/daterangepicker/moment.js');
//        echo tiny\View::script('bootstrap/plugin/daterangepicker/daterangepicker.js');
//        echo tiny\View::style('bootstrap/plugin/daterangepicker/daterangepicker-bs3.css');
//        echo tiny\View::script('bootstrap/plugin/multiselect/bootstrap-multiselect.js');
//        echo tiny\View::style('bootstrap/plugin/multiselect/bootstrap-multiselect.css');

        echo $this->_css();
        echo $this->html;
        echo $this->_js();
    }

    private function _css()
    {
        return <<<CSS
<style>
#$this->id, #$this->id table {
    font-size: 12px;
}
.data-tables-submit{
    display: inline-block;
}
.sort-default {
    color: #999999;
}
.sort-default:hover, .sort-active {
    color: #000;
}
.pointer {
    cursor: pointer;
}
</style>
CSS;
    }

    private function _js()
    {
        return <<<JS
<script>
function openWindowWithPost(url, param)
{
    var newWindow = window.open(url, 'newWindow');
    if (!newWindow)
        return false;

    var html = "";
    html += "<html><head></head><body><form id='formid' method='post' action='" + url + "'>";

    if(typeof param == 'string'){
        var newParam = {}, seg = param.split('&'), len = seg.length, i = 0, s;
        for (;i<len;i++) {
            if (!seg[i]) { continue; }
            s = seg[i].split('=');
            newParam[s[0]] = s[1];
        }
    }else
        newParam = param;

    \$.each(newParam, function(i, n){
        html += "<input type='hidden' name='" + i + "' value='" + n + "'/>";
    });

    html += "</form><script type='text/javascript'>document.getElementById('formid').submit();";
    html += "<\/script></body></html>".toString().replace('/^.+?\*|\\(?=\/)|\*.+?\$/gi', "");
    newWindow.document.write(html);

    return newWindow;
}
// 跳到某一页
function goPage(obj) {
    if (!obj.hasClass('disabled') && !obj.hasClass('active')) {
        var _p = obj.attr('name');
        var _a = obj.siblings('.active');
        if (_p == 'prev') {
            _p = _a.prev().attr('name');
        } else if (_p == 'next') {
            _p = _a.next().attr('name');
        } else if (_p == 'first') {
            _p = '1';
        }
        obj.siblings('.data-tables-jump').children('input[name="_page[current]"]').val(_p);
        dataTablesSubmit();
    }
}
function dataTablesSubmit(exp){
    if(typeof exp != 'undefined'){
        openWindowWithPost('{$this->url}', \$("#{$this->id}-form").serialize() + '&_export=true');
    }else{
        var body = \$("#{$this->id}-body");
        var column = body.data('column');
        $.ajax({
            type : 'post',
            url : '{$this->url}',
            data : \$("#{$this->id}-form").serialize(),
            dataType : 'json',
            beforeSend : function(){
                body.html('<tr><td colspan="' + column + '"><div class="progress progress-striped active" style=" margin:15px 50px;"><div class="progress-bar" role="progressbar" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100" style="width: 100%;"></div></div></td></tr>');
            },
            error: function(){
                body.html('<tr><td colspan="' + column + '"><div class="alert alert-danger" role="alert">_(:з)∠)_&nbsp;&nbsp;&nbsp;加载出错或超时了</div></td></tr>');
            },
            success : function(json){
                if(json.ret == 1){
                    // 填充body
                    var body_html = '';
                    $.each(json.msg.rows, function(i, n){
                        body_html += '<tr>';
                        $.each(n, function(subI, subN){
                            if(subN == null) subN = '';
                            body_html += '<td>' + subN + '</td>';
                        });
                        body_html += '</tr>';
                    });
                    body.html(body_html);
                    //==开始处理分页
                    if(typeof json.msg.per != 'undefined'){
                        // 处理分页
                        \$("#{$this->id}-page-left").html('第 ' + ((json.msg.current - 1) * json.msg.per + 1) + ' 到 ' + (json.msg.current * json.msg.per <= json.msg.total ? json.msg.current * json.msg.per : json.msg.total) + ' 条; ' +
                         '共 ' + json.msg.total + ' 条 ' + json.msg.page + ' 页; 每页显示 ' +
                         '<select size="1" name="_page[per]" class="input-sm data-tables-submit" onchange="dataTablesSubmit();">' +
                         '<option value="10" ' + (json.msg.per == 10 ? "selected='selected'" : '') + '>10</option>' +
                         '<option value="25" ' + (json.msg.per == 25 ? "selected='selected'" : '') + '>25</option>' +
                         '<option value="50" ' + (json.msg.per == 50 ? "selected='selected'" : '') + '>50</option>' +
                         '<option value="100" ' + (json.msg.per == 100 ? "selected='selected'" : '') + '>100</option>' +
                         '</select> 条');
                        var pageNav = ''; // 分页按钮
                        // 最大按钮
                        if (json.msg.current < 3)
                            var maxPage = 5;
                        else
                            var maxPage = json.msg.current + 2;
                        // 填充按钮
                        for (var i = 1; i <= maxPage; i++) {
                            if (i < json.msg.current - 2) continue;
                            if (i > json.msg.page) break;
                            pageNav += '<li' + (json.msg.current == i ? ' class="active"' : '') + ' name="' + i + '" onclick="goPage($(this))"><a>' + i + '</a></li>';
                        }
                        \$('#{$this->id}-page-right').html('<ul class="pagination" style="margin:0;">' +
                            '<span style="margin-left: 10px;" class="data-tables-jump">我跳: <input type="text" class="form-control input-sm data-tables-submit" style="display: inline-block; width: 50px;" name="_page[current]" onchange="dataTablesSubmit();" value="' + json.msg.current + '"/></span>' +
                            '<li class="first' + (json.msg.current == 1 ? ' disabled' : '') + '" name="first" onclick="goPage($(this))">' +
                            '<a>首页</a></li>' +
                            '<li class="prev' + (json.msg.current == 1 ? ' disabled' : '') + '" name="prev" onclick="goPage($(this))">' +
                            '<a>前页</a></li>' + pageNav +
                            '<li class="next' + (json.msg.current * json.msg.per >= json.msg.total ? ' disabled' : '') + '" name="next" onclick="goPage($(this))">' +
                            '<a>后页</a>' +
                            '</li>' +
                            '<li class="last' + (json.msg.current * json.msg.per >= json.msg.total ? ' disabled' : '') + '" name="' + json.msg.page + '" onclick="goPage($(this))">' +
                            '<a>末页</a>' +
                            '</li></ul>');
                    }else{ // 不分页
                        \$("#{$this->id}-page-left").html('共查找到 ' + json.msg.total + ' 条记录');
                    }

                    // js
                    if (json.msg.js != '') {
                        \$('#{$this->id}-js').html('<script>' + json.msg.js + '<\/script>');
                    }
                }else{
                    var html = '';
                    if(json.ret == 2)
                        var alert = 'info';
                    else
                        var alert = 'danger';
                    body.html('<tr><td colspan="' + column + '"><div class="alert alert-' + alert + '" role="alert">_(:з)∠)_&nbsp;&nbsp;&nbsp;' + json.msg + '</div></td></tr>');
                    \$("#{$this->id}-page-left").html('共 0 条记录');
                }
            }
        });
    }
}
function dataTablesSort(obj){
    // 清除其他排序的状态
    obj.parent().parent().find('.sort-active').not(obj).removeClass('sort-active').addClass('glyphicon-sort sort-default').children().prop('disabled', true).val('');
    var sort = obj.children().val();
    switch(sort){
        case '':
            obj.removeClass('glyphicon-sort sort-default').addClass('glyphicon-arrow-down sort-active').children().prop('disabled', false).val('desc');
            break;
        case 'desc':
            obj.removeClass('glyphicon-arrow-down sort-default').addClass('glyphicon-arrow-up').children().prop('disabled', false).val('asc');
            break;
        case 'asc':
            obj.removeClass('glyphicon-arrow-up sort-active').addClass('glyphicon-sort sort-default').children().prop('disabled', true).val('');
            break;
    }
    dataTablesSubmit();
}
\$(document).ready(function () {
    $('#$this->id .glyphicon-question-sign').tooltip();
    dataTablesSubmit();
    $this->js
});
</script>
JS;
    }

    private function _makeDateRangeJs($class, $format)
    {
        if(strpos($format, ' ') !== false) // 根据空格判断是否需要显示小时和分钟
            $timePicker = 'timePicker : true, timePickerIncrement: 1, timePicker12Hour: true,';
        else
            $timePicker = '';

        $js = <<<JS
\$('#$this->id .$class').daterangepicker({
    startDate: moment().subtract('days', 29),
    endDate: moment(),
    dateLimit: { days: 60 },
    showDropdowns: true,
    showWeekNumbers: true,
    $timePicker
    ranges: {
        //'今日': [moment(), moment()],
        //'昨日': [moment().subtract('days', 1), moment().subtract('days', 1)],
        '最后1日': [moment().subtract('days', 1), moment()],
        '最后7日': [moment().subtract('days', 6), moment()],
        '最后30日': [moment().subtract('days', 29), moment()],
        '这个月': [moment().startOf('month'), moment().endOf('month')],
        '上个月': [moment().subtract('month', 1).startOf('month'), moment().subtract('month', 1).endOf('month')]
    },
    buttonClasses: ['btn btn-default'],
    applyClass: 'btn-small btn-primary',
    cancelClass: 'btn-small',
    format: '$format',
    separator: ' ~ ',
    locale: {
        applyLabel: '确定',
        fromLabel: '从',
        toLabel: '到',
        customRangeLabel: '我要自己选',
        daysOfWeek: ['日', '一', '二', '三', '四', '五', '六'],
        monthNames: ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月'],
        firstDay: 1
    }
}
);
JS;
        return $js;
    }

    private function _makeDateTimeJs($class, $format)
    {
        if(strpos($format, ' ') !== false) // 根据空格判断是否需要显示小时和分钟
            $timePicker = 'timePicker : true,';
        else
            $timePicker = '';

        $js = <<<JS
\$('#$this->id .$class').daterangepicker({
    showDropdowns: true,
    singleDatePicker: true,
    startDate: moment(),
    $timePicker
    format: '$format'
});
JS;
        return $js;
    }

}
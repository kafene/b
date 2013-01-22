<?php

class b {
  static $db, $entries;
  static function run() {
    self::$db = new \PDO('sqlite:b.db');
    self::$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    self::$db->query("CREATE TABLE IF NOT EXISTS b
    ( id INTEGER PRIMARY KEY
    , date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    , desc TEXT NOT NULL DEFAULT ''
    , link TEXT NOT NULL DEFAULT '' UNIQUE);");
    self::handleRequest();
    $s = empty($_GET['s']) ? '%' : $_GET['s'];
    $st = self::$db->prepare('SELECT * FROM b WHERE desc LIKE ? ORDER BY date DESC');
    $st->execute(["%$s%"]);
    self::$entries = $st->fetchAll(\PDO::FETCH_ASSOC);
  }
  static function handleRequest() {
    if(empty($_POST['action'])) { return; }
    $error = true; $result = [];
    $id = $result['id'] = !empty($_POST['id']) ? $_POST['id'] : false;
    error_reporting(0); ini_set('display_errors', 0);
    try {
      switch($_POST['action']) {
        case 'add':
          $result['url'] = $_POST['url'];
          $force = $result['force'] = !empty($_POST['force']);
          list($link, $desc) = false === strpos($_POST['url'], ' ')
            ? [$_POST['url'], '']
            : explode(' ', $_POST['url'], 2);
          if(($ch = curl_init($link)) === false) {
            if(!$force) { throw new \Exception('curlerr2'); }
          } else {
            curl_setopt_array($ch, [
                \CURLOPT_RETURNTRANSFER => true
              , \CURLOPT_SSL_VERIFYPEER => false
              , \CURLOPT_FOLLOWLOCATION => true
              , \CURLOPT_AUTOREFERER    => true
              , \CURLOPT_TIMEOUT        => 5
              , \CURLOPT_USERAGENT      => 'Mozilla, AppleWebKit, Gecko, Chrome'
            ]);
            if(($body = curl_exec($ch)) === false && !$force)
                throw new \Exception('curlerr2');
          }
          if(!empty($body)) {
            if(!preg_match('@<title>([^<]+)@', $body, $m)) {
              $desc = '(unknown title)'.($desc ? " - $desc" : '');
            } else {
              $ret = $m[1];
              $ec = mb_detect_encoding($ret, 'UTF-8,ISO-8859-1', true);
              if($ec !== 'UTF-8') $ret = mb_convert_encoding($ret,'UTF-8',$ec);
              $ret = trim(html_entity_decode($ret, \ENT_QUOTES, 'UTF-8'));
              $desc = $ret.($desc ? " - $desc" : '');
            }
          } else { $desc = $link.($desc ? " - $desc" : ''); }
          self::$db->prepare('INSERT INTO b (desc,link) VALUES (?,?)')->execute([$desc, $link]);
          $error = false;
          break;
        case 'delete':
          if($id) {
            self::$db->prepare('DELETE FROM b WHERE id=?')->execute([$id]);
            $error = false;
          }
          break;
        case 'settitle':
          if($id && !empty($_POST['title'])) {
            self::$db->prepare('UPDATE b SET desc=? WHERE id=?')->execute([$_POST['title'], $id]);
            $error = false;
            $result['title'] = self::formatDesc($_POST['title']);
            $result['rawTitle'] = $_POST['title'];
          }
          break;
        case 'setlink':
          if($id && !empty($_POST['link'])) {
            self::$db->prepare('UPDATE b SET link=? WHERE id=?')->execute([$_POST['link'], $id]);
            $error = false;
            $result['link'] = $_POST['link'];
          }
          break;
        default: return false;
      }
    } catch(\Exception $e) { $result['message'] = $e->getMessage(); }
    $result['result'] = $error ? false : true;
    exit(json_encode($result, \JSON_FORCE_OBJECT));
  }
  static function formatDesc($desc) {
    $desc = '<b>'.self::h($desc);
    foreach(preg_match_all('@#[a-z0-9-_]+@', $desc, $m) ? $m[0] : [] as $i => $m)
      $desc = str_replace($m, '', $desc).(0 === $i ? '</b>' : '')
            . '<a class="tag" href="?s='.rawurlencode($m).'">'.$m.'</a> , ';
    return rtrim($desc, ', ').(false === strpos($desc, '</b>') ? '</b>' : '');
  }
  static function h($str) {
    $flags = \ENT_QUOTES | \ENT_HTML5 | \ENT_DISALLOWED | \ENT_SUBSTITUTE;
    return htmlspecialchars($str, $flags, 'UTF-8', false);
  }
}

b::run();
?><!doctype html><html><head><meta charset="utf-8"><title>b</title><style>
html { font:16px/125% "Segoe UI", "Droid Sans", "DejaVu Sans", sans-serif; }
body { max-width:900px; margin:0 auto; } a#home { color:#000; float:right; }
a { color:#990000; } .link a { color:#869DD4; } .entry b { display:block; }
.entry b:hover { background:#FFFAD8; cursor:pointer; }
header, .entry { border-bottom:1px solid #ccc; padding:5px; }
header { padding:1em 5px; } 
.entry .link:hover { background:#EEFFEE; cursor:pointer; }
input { width:50%; border:1px solid #ccc; padding:3px 2px; }
</style></head><body><div class="content"><header><form>
<input type="text" value="" name="query" placeholder="http(s)://... | search">
<a id="home" href="<?= basename($_SERVER['SCRIPT_NAME']) ?>">home</a>
</form></header><?php foreach(b::$entries as $e): ?>
<div class="entry" id="entry_<?= $e['id'] ?>" data-id="<?= $e['id'] ?>" data-title="<?= b::h($e['desc']) ?>">
<div class="title"><?= b::formatDesc($e['desc']); ?></div>
<div class="link"><a target="_blank" href="<?= b::h($e['link']) ?>"><?= b::h($e['link']) ?></a></div>
</div><!-- /.entry --><?php endforeach; ?></div><!-- /.content -->
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
<script>
function deleteEntry(id) {
  $.post('',{action:'delete',id:id},function(data){
    var data = JSON.parse(data);
    if(data.message) { alert(data.message); }
    if(data.result === true) { $('#entry_'+data.id).remove(); }
  });
}
function setTitle(id, title) {
  $.post('',{action:'settitle',id:id,title:title},function(data){
    var data = JSON.parse(data);
    if(data.message) { alert(data.message); }
    if(data.result === true) {
      $('#entry_'+data.id+' .title').html(data.title);
      $('#entry_'+data.id).attr('data-title', data.rawTitle);
    } else { alert('err1'); }
  });
}
function setLink(id, link) {
  $.post('',{action:'setlink',id:id,link:link },function(data){
    var data = JSON.parse(data);
    if(data.message) { alert(data.message); }
    if(data.result === true) {
      $('#entry_'+data.id+' a.link').html(data.link);
      $('#entry_'+data.id+' a.link').attr('href', data.link);
    } else { alert('err2'); }
  });
}
function addUrl(url, force) {
  var input = $(':input');
  input.attr('disabled', 'disabled');
  $.post('',{action:'add',url:url,force:force?'1':'0'},function(data){
    var data = JSON.parse(data);
    if(!data.force && data.message === 'curlerr2') {
      if(confirm('could not fetch, force?')) { addUrl(data.url, true); return; }
      input.removeAttr('disabled'); input.focus(); return;
    }
    if(data.message) { alert(data.message); }
    if(data.result === true) { document.location.reload(); }
    input.removeAttr('disabled'); input.focus();
  });
}
$('div.title b').click(function(e) {
  var ret, tgt = e.target, id = $(tgt).parents('.entry').data('id');
  var p = $(tgt).parents('.entry').data('title').split("\n").join(' ').trim();
  if(!(ret = prompt('new name to rename, or `-` to delete #'+id, p))) { return false; }
  if(ret === '-' && confirm('really delete?')) { deleteEntry(id); return false; }
  setTitle(id, ret); return false;
});
$('div.link').click(function(e) {
  var tgt = e.target, ret;
  if(tgt && tgt.className === 'link') {
    var id = $(tgt).parents('.entry').data('id');
    var href = $('a', tgt).attr('href');
    if(null === (ret = prompt('new url for #'+id+':', href))) { return; }
    setLink(id, ret);
  }
});
$('form').submit(function(e) {
  if(e.preventDefault) { e.preventDefault(); }
  var q = $(':input').val();
  if(0 === (q.indexOf('http:') & q.indexOf('https:'))) { addUrl(q); return false; }
  document.location.href = "?s="+encodeURIComponent(q); return false;
});
</script>
</body>
</html>
<?php

class B {
    public $db;
    static $entries = [], $hashtags = [];

    function __construct($file = 'b.db') {
        $new = !file_exists($file);
        $this->db = new \PDO("sqlite:$file");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if($new) {
            $this->db->prepare("CREATE TABLE IF NOT EXISTS b (
                id INTEGER PRIMARY KEY,
                date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                desc TEXT NOT NULL DEFAULT '',
                link TEXT NOT NULL DEFAULT '' UNIQUE
            );")->execute();
        }
    }

    static function init() {
        $b = new static;
        $b->server();
        $filter = empty($_GET['filter']) ? false : $_GET['filter'];
        $st = $b->db->prepare('SELECT * FROM b WHERE desc LIKE ? ORDER BY date DESC');
        $st->execute(["%".($filter ?: '%')."%"]);
        static::$entries = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $sql = 'SELECT desc as ht FROM b WHERE desc LIKE "%#%"';
        $hashtags = [];
        array_map(function($tag) use(&$hashtags) {
            $tag = $tag['ht'];
            if(preg_match_all('/\W(#\w+)/', $tag, $tags)) {
                $hashtags = array_merge($hashtags, $tags[1]);
            }
        }, $b->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC));
        static::$hashtags = $hashtags;
    }

    function server() {
        $error = false;
        $result = null;
        set_exception_handler(function(\Exception $e) use(&$result, &$error) {
            $result['message'] = $e->getMessage() ?: 'unknown error';
            $result['error'] = $error = true;
            exit(json_encode($result, JSON_FORCE_OBJECT));
        });
        # Only reply to JSON requests.
        if(empty($_POST['action'])
        || 'XMLHttpRequest' !== getenv('HTTP_X_REQUESTED_WITH')
        || 'POST' !== getenv('REQUEST_METHOD')) {
            return;
        }
        list($error, $result, $action) = [true, [], strtolower($_POST['action'])];
        $result['id'] = $id = (!empty($_POST['id']) ? $_POST['id'] : false);
        # Add an entry
        if('add' == $action && isset($_POST['url']))
        {
            $result['url'] = $_POST['url'];
            $result['force'] = !empty($_POST['force']);
            list($url, $desc) = strpos($_POST['url'], ' ')
                ? explode(' ', $_POST['url'], 2)
                : [$_POST['url'], ''];
            $this->add($url, $desc, !empty($_POST['force']));
            $error = false;
        }
        # Delete an entry
        elseif('delete' == $action && $id)
        {
            $this->db->prepare("DELETE FROM b WHERE id = :id")->execute([$id]);
            $error = false;
        }
        # Set an entry's title
        elseif('settitle' == $action && $id && !empty($_POST['title']))
        {
            $sql = "UPDATE b SET desc = ? WHERE id = ?";
            $this->db->prepare($sql)->execute([$_POST['title'], $id]);
            $result['title'] = self::formatDesc($_POST['title']);
            $result['rawTitle'] = $_POST['title'];
            $error = false;
        }
        # Set an entry's link
        elseif('setlink' == $action && $id && !empty($_POST['link']))
        {
            $sql = "UPDATE b SET link = ? WHERE id = ?";
            $this->db->prepare($sql)->execute([$_POST['link'], $id]);
            $result['link'] = $_POST['link'];
            $error = false;
        }
        $result['result'] = !$error;
        exit(json_encode($result, JSON_FORCE_OBJECT));
    }

    function add($url, $appendDesc = '', $force = false) {
        if(!$force && (($ch = curl_init($url)) === false)) {
            throw new \Exception('could not init curl');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if(!$force && (($body = curl_exec($ch)) === false)) {
            throw new \Exception('could not fetch');
        }
        curl_close($ch);
        if(!empty($body)) {
            $desc = preg_match('@<title[^>]*>([^<]+)@', $body, $m)
                ? $m[1]
                : preg_replace('/^http\:\/\//i', '', $url);
            $enc = mb_detect_encoding($desc, 'UTF-8,ISO-8859-1', true);
            if($enc !== 'UTF-8') {
                $desc = mb_convert_encoding($desc, 'UTF-8', $enc);
            }
            $desc = trim(html_entity_decode($desc, ENT_QUOTES, 'UTF-8')).' '.$appendDesc;
        } else {
            $desc = $url;
        }
        $sql = 'INSERT INTO b (desc, link) VALUES (?, ?)';
        return $this->db->prepare($sql)->execute([$desc, $url]);
    }

    static function formatDesc($desc) {
        $desc = static::h($desc);
        if(preg_match_all('@\b#\w+\b@', $desc, $m)) {
            foreach($m[0] as $m) {
                $m = trim($m);
                $link = '</a> <a class="hash" href="?filter=';
                $link.= rawurlencode($m).'">'.$m.'</a>';
                $desc = str_replace($m, trim($link), $desc);
                $desc = str_replace('</a></a>', '</a>', $desc);
            }
        }
        return $desc;
    }

    static function h($v) {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

B::init();

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>b</title>
<style>
body { background-color: #fff; font-family: sans-serif; }
a { color: #2F2318; }
a.hash { color: #6979A8; }
a.desc { color: #869DD4; }
.entry {
  display: block;
  padding-bottom: 10px;
  padding-top: 10px;
  border: 1px solid #fff;
  border-bottom: 1px solid #6979A8;
}
header { padding-bottom: 10px; }
.entry { padding-left: 1%; }
.entry a.title:hover, .entry a.link:hover {
  cursor:pointer; text-decoration:underline;
}
input { width: 500px; }
#hashtags {
    max-width: 40%;
    border: 1px solid #ccc;
    margin: 1em;
    padding: 1em;
}
#hashtags h2 { margin: 0 0 0.5em; }
</style>
<script src="http://code.jquery.com/jquery-2.0.0.min.js"></script>
</head>
<body>
<div class="content">

<header>
<form>
<input autofocus type="text" name="query" value="" placeholder="http://... <- enter new url here and press return | or enter filter query">
</form>
</header>

<?php foreach(B::$entries as $e): ?>
<div class="entry"
    id="entry_<?= $e['id'] ?>"
    data-id="<?= $e['id'] ?>"
    data-title="<?= B::h($e['desc']) ?>"
    data-href="<?= B::h($e['link']) ?>"
    data-date="<?= B::h($e['date']) ?>">
<a class="desc" href="<?= B::h($e['link']) ?>"><?= B::formatDesc($e['desc']) ?></a><br>
<small>edit: <a class="title">title</a> / <a class="link" id="link_<?= $e['id'] ?>">link</a></small>
</div>
<?php endforeach; ?>

<div id="hashtags">
<h2>hashtags</h2>
<a class="hash" href="?">clear</a>
<?php foreach(B::$hashtags as $tag): ?>
<a class="hash" href="?filter=<?= rawurlencode($tag) ?>"><?= $tag ?></a>
<?php endforeach; ?>
</div>

</div>
<script>
function parse_json(data) {
    try { data = JSON.parse(data); } catch(a) { alert(data); }
    return data;
}

function addUrl(url, force) {
    var input = $(':input');
    input.attr('disabled', 'disabled');
    $.post('', {
        action: 'add',
        url: url,
        force: force ? '1' : '0'
    }, function(data) {
        var data = parse_json(data);
        if (!data.force && data.message === 'could not fetch') {
            if (confirm('could not fetch, add anyway?')) {
                addUrl(data.url, true);
                return;
            }
            input.removeAttr('disabled');
            input.focus();
            return;
        }
        if (data.message) alert(data.message);
        if (data.result === true) document.location.reload();
        input.removeAttr('disabled');
        input.focus();
    });
}

$('.link').click(function(ev) {
    var target = ev.target;
    var tid = $(target).attr('id').replace(/[^0-9]*/, '');
    var href = $('#entry_'+ tid).data('href');
    var link = prompt('edit url', href);
    if (link === null) return;
    $.post('', {
        action: 'setlink',
        id: tid,
        link: link
    }, function(data) {
        var data = parse_json(data);
        if (data.message) alert(data.message);
        if (data.result === true) {
            $('#entry_'+ data.id +' .desc').attr('href', data.link);
        } else {
            alert('err');
        }
    });
});

$('.title').click(function(ev) {
    var target = ev.target;
    var tid = $(target).closest('.entry').data('id');
    var rawTitle = $(target).closest('.entry').data('title');
    var title = prompt('rename, or `-` to delete', rawTitle);
    if (!title) return;
    if (title === '-') {
        if(confirm('really delete?')) {
            $.post('', {
                action: 'delete',
                id: tid
            }, function(data) {
                var data = JSON.parse(data) || alert(data);
                if (data.message) alert(data.message);
                if (data.result === true) $('#entry_'+ data.id).remove();
            });
        }
        return;
    }
    $.post('', {
        action: 'settitle',
        id: tid,
        title: title
    }, function(data) {
        var data = parse_json(data);
        if (data.message) alert(data.message);
        if (data.result === true) {
            $('#entry_'+ data.id +' .desc').html(data.title);
            $('#entry_'+ data.id).attr('data-title', data.rawTitle);
        } else {
            alert('err');
        }
    });
});

$('form').submit(function(e) {
    var query = $(':input').val();
    if (query.indexOf('http:') === 0 || query.indexOf('https:') === 0) {
        addUrl(query);
        return false;
    }
    document.location.href = "?filter="+ encodeURIComponent(query);
    return false;
});
</script>
</body>
</html>
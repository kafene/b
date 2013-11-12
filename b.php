<?php

class BookmarkManager
{
    /**
     * @var \PDO SQLite Database Connection
     */
    protected $db;

    /**
     * @var array SQL Queries
     */
    protected $sql = [
        'add'      => "INSERT INTO b (title, link) VALUES (?, ?)",
        'delete'   => "DELETE FROM b WHERE id = ?",
        'settitle' => "UPDATE b SET title = ? WHERE id = ?",
        'setlink'  => "UPDATE b SET link = ? WHERE id = ?",
    ];

    /**
     * Constructor
     *
     * @param string $filename SQLite Database filename
     */
    public function __construct($filename = 'b.db')
    {
        # Start an output buffer
        ob_start();

        # Set up error handlers
        $this->handleErrors();

        # Strip out existing sqlite DSN prefix
        $filename = preg_replace('/^sqlite:/i', '', $filename);

        $this->db = new \PDO("sqlite:$filename", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        # Create tables
        $this->db->exec("CREATE TABLE IF NOT EXISTS b (
            id INTEGER PRIMARY KEY,
            time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            title TEXT NOT NULL DEFAULT '',
            link TEXT NOT NULL DEFAULT '' UNIQUE
        );");

        # Run AJAX request handler
        $this->server();
    }

    /**
     * Send a JSON response
     *
     * @param array $params data to json-encode.
     */
    protected function json(array $params)
    {
        $defaults = [
            'error' => false,
            'message' => null,
        ];
        $params = array_merge($defaults, $params);

        $json = json_encode($params, JSON_FORCE_OBJECT);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \Exception("JSON Encoding Failed.");
        }

        header('HTTP/1.1 200 OK', true, 200);
        header('Content-Type: application/json;charset=UTF-8');
        exit($json);
    }

    /**
     * Set up error handlers
     *
     * Automatically send JSON errors for AJAX requests,
     * otherwise prints an informative error message.
     */
    protected function handleErrors()
    {
        set_exception_handler($ex = function (\Exception $e) {
            # Flush output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }

            if ($this->isAjax()) {
                $this->json([
                    'error'     => true,
                    'file'      => $e->getFile(),
                    'code'      => $e->getCode(),
                    'line'      => $e->getLine(),
                    'message'   => $e->getMessage(),
                    'trace'     => $e->getTrace(),
                    'exception' => $e,
                ]);
            }
            # Regular HTTP requests
            $errstr = 'HTTP/1.1 500 Internal Server Error';
            header($errstr, true, 500);
            exit(sprintf(
                '<html><head><title>%s</title></head><body><h3>%s</h3>
                <ul style="font-family:monospace;white-space:pre-line">
                <li>Error [%d]: %s</li><li>File: %s</li><li>Line: %d</li>
                </ul><hr><pre>%s</pre></body></html>',
                $errstr,
                $errstr,
                $e->getCode(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ));
        });

        # Convert errors to exceptions.
        set_error_handler(function ($n, $s, $f, $l) use ($ex) {
            throw new \ErrorException($s, $n, 0, $f, $l);
        });
    }

    /**
     * Determine if the request is an AJAX request
     * It must have an 'action' parameter to be considered one.
     *
     * @return boolean
     */
    protected function isAjax()
    {
        $xhr = 'HTTP_X_REQUESTED_WITH';
        return array_key_exists($xhr, $_SERVER)
            && array_key_exists('REQUEST_METHOD', $_SERVER)
            && array_key_exists('action', $_POST)
            && 0 === strcasecmp('POST', $_SERVER['REQUEST_METHOD'])
            && 0 === strcasecmp('xmlhttprequest', $_SERVER[$xhr])
            && !empty($_POST['action']);
    }

    /**
     * Adds a bookmark link to the database.
     *
     * @param string $link Link/URL
     */
    protected function add($link)
    {
        $link = trim($link);
        list($link, $append) = strpos($link, ' ')
            ? explode(' ', $link, 2)
            : array($link, '');

        $title = $this->extractTitle($link);
        $title = trim("$title $append");

        $this->db->prepare($this->sql['add'])->execute([$title, $link]);
    }

    /**
     * Handles AJAX requests and sends a JSON response.
     */
    protected function server()
    {
        if (!$this->isAjax()) {
            return;
        }

        # Init response data
        $r['action'] = $action = strtolower($_POST['action']);
        $r['id'] = $id = empty($_POST['id']) ? null : $_POST['id'];
        $r['link'] = $link = empty($_POST['link']) ? null : $_POST['link'];
        $r['title'] = $title = empty($_POST['title']) ? null : $_POST['title'];

        if ($r['title']) {
            $r['rawTitle'] = $title;
            $r['title'] = $this->formatTitle($title);
            $r['tags'] = $this->formatTags($title);
        }

        if ('add' === $action && $link) {
            $this->add($link);
        } elseif ('delete' === $action && $id) {
            $this->db->prepare($this->sql['delete'])->execute([$id]);
        } elseif ('settitle' === $action && $id && $title) {
            $this->db->prepare($this->sql['settitle'])->execute([$title, $id]);
        } elseif ('setlink' === $action && $id && $link) {
            $this->db->prepare($this->sql['setlink'])->execute([$link, $id]);
        } else {
            $r['error'] = true;
            $r['message'] = "Invalid action.";
        }

        # Send the response
        $this->json($r);
    }

    /**
     * Fetch the contents of a link/url
     *
     * @param string $link
     *
     * @return string
     */
    protected function fetch($link)
    {
        $ch = curl_init($link);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        # curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $ret = curl_exec($ch);
        curl_close($ch);
        return $ret;
    }

    /**
     * Extract a title from a link
     * If the title can not be detected from a fetched
     * url, it will be the link stripped of its protocol.
     *
     * @param string $link
     *
     * @return string
     */
    protected function extractTitle($link)
    {
        $body = $this->fetch($link);

        # Either use title extracted from the site, or the link w/o protocol.
        $title = ($body && preg_match('/<title[^>]*>([^<]+)/i', $body, $m))
            ? $m[1]
            : preg_replace('/^[a-z]+:\/\//i', '', $link);

        # Fix encoding
        $encoding = mb_detect_encoding($title, 'UTF-8,ISO-8859-1', true);
        if ($encoding !== 'UTF-8') {
            $title = mb_convert_encoding($title, 'UTF-8', $encoding);
        }

        return trim(html_entity_decode($title, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Get all entries for display on the page
     *
     * @return array
     */
    public function getEntries()
    {
        $filter = empty($_GET['filter']) ? '%' : $_GET['filter'];
        $filter = "%$filter%";

        $sql = "SELECT * FROM b WHERE title LIKE ? ORDER BY time DESC";
        $st = $this->db->prepare($sql);
        $st->execute([$filter]);

        $entries = $st->fetchAll() ?: [];
        foreach ($entries as &$e) {
            $e['rawTitle'] = $this->e($e['title']);
            $e['link'] = $this->e($e['link']);
            $e['time'] = $this->e($e['time']);
            $e['tags'] = $this->formatTags($e['title']);
            $e['title'] = $this->formatTitle($e['title']);
        }

        return $entries;
    }

    /**
     * Format an entry title
     *
     * @param string $title The entry title
     * @param boolean $tags whether to format tags in the title
     *
     * @return string
     */
    protected function formatTitle($title)
    {
        return trim(preg_replace('/\W(#\w+)/', '', $this->e($title)));
    }

    /**
     * Format tags in an entry title
     *
     * @param string $title The entry title
     *
     * @return string
     */
    protected function formatTags($title)
    {
        $format = '<a class="hash" href="?filter=%s">%s</a>';
        return trim(join(' ', array_map(function ($tag) use ($format) {
            return sprintf($format, rawurlencode($tag), $tag);
        }, preg_match_all('/\W(#\w+)/', $this->e($title), $m) ? $m[1] : [])));
    }

    /**
     * Escape some string with htmlspecialchars
     *
     * @param string The string to escape
     *
     * @return string
     */
    protected function e($str)
    {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8', false);
    }

}

$b = new BookmarkManager;

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width; initial-scale=1.0;">
<title>b</title>
<script src="http://code.jquery.com/jquery-2.0.3.min.js"></script>
<link href='http://fonts.googleapis.com/css?family=Electrolize' rel='stylesheet' type='text/css'>
<link rel="stylesheet" href="http://cdnjs.cloudflare.com/ajax/libs/normalize/2.1.3/normalize.min.css"/>
<style>
* {
    box-sizing: border-box;
}
body {
    background-color: #fff;
    font-family: sans-serif;
    margin: 10px;
    max-width: 800px;
}
a {
    color: #2F2318;
    text-decoration: none;
    cursor: pointer;
}
a:hover {
    text-decoration: underline;
}
a.hash {
    color: #6979A8;
}
a.link {
    color: #869DD4;
}
header {
    padding: 0 0 10px 0;
}
input {
    padding: 2px;
    margin: 0;
    width: 100%;
}
.tags {
    font-size: smaller;
}
article {
    display: block;
    padding: 10px 0 10px 1%;
    border: 1px solid #fff;
    border-bottom: 1px solid #6979A8;
}
article a.title {
    width: 100%;
    display: block;
}
article a.title:hover {
    background-color: #FFFAD8;
}
article a.link {
    color: #999;
    word-wrap: break-word;
    font-size: smaller;
}
</style>
</head>
<body>

<header>
<form>
<input autofocus type="text" name="query" id="query">
</form>
</header>

<?php foreach ($b->getEntries() as $e): ?>
<article class="entry"
    id="entry_<?= $e['id'] ?>"
    data-id="<?= $e['id'] ?>"
    data-title="<?= $e['rawTitle'] ?>"
    data-href="<?= $e['link'] ?>"
    data-time="<?= $e['time'] ?>">
    <a class="title" title="<?= $e['title'] ?> (<?= $e['time'] ?>)">
        <?= $e['title'] ?>
    </a>
    <a class="link" target="_blank" href="<?= $e['link'] ?>">
        <?= $e['link'] ?>
    </a>
    <div class="tags">
        <?= $e['tags'] ?>
    </div>
</article>
<?php endforeach ?>

<script>
$('.title').click(function (e) {
    var id = $(this).parent('article').data('id');
    var rawTitle = $(this).parent('article').data('title');
    var ret = prompt('rename or, `-` to delete', rawTitle);

    if (!ret) {
        return;
    }

    if (ret === '-') {
        if (confirm('really delete?')) {
            $.post('', {action: 'delete', id: id}, function (data) {
                data.message && alert(data.message);

                data.error || $('#entry_'+data.id).remove();
            });
        }

        return;
    }

    $.post('', {action: 'settitle', id: id, title: ret}, function (data) {
        data.message && alert(data.message);

        if (!data.error) {
            $('#entry_'+data.id+' .title').html(data.title);
            $('#entry_'+data.id+' .tags').html(data.tags);
            $('#entry_'+data.id).data('title', data.rawTitle);
        }
    });
});


$('.entry').dblclick(function (e) {
    var id = $(this).data('id');
    var href = $(this).data('href');
    var ret = prompt('edit link', href);

    if (!ret) {
        return;
    }

    $.post('', {action: 'setlink', id: id, link: ret}, function (data) {
        data.message && alert(data.message);

        if (!data.error) {
            $('#entry_'+data.id+' .link').html(data.link);
            $('#entry_'+data.id+' .link').prop('href', data.link);
            $('#entry_'+data.id).data('href', data.link);
        }
    });
});


$('form').submit(function (e) {
    var input = $('#query');
    var query = $('#query').val();
    if (/^https?:\/\//i.test(query)) {
        input.prop('disabled', true);

        $.post('', {action: 'add', url: query}, function (data) {
            data.message && alert(data.message);
            data.error || document.location.reload();
            input.prop('disabled', false);
            input.focus();
        });

        return false;
    }

    document.location.href = "?filter=" + encodeURIComponent(query);

    return false;
});

$("#query").focus();
</script>
</body>
</html>

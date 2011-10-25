<?php
define('LIB_PATH', dirname(dirname(__FILE__)) . '/lib/');
require_once(LIB_PATH . 'limonade/lib/limonade.php');

function answer($code, $msg)
{
  status($code);
  return $msg;
}

function configure()
{
  require_once(LIB_PATH . 'predis/lib/Predis/Autoloader.php');
  Predis\Autoloader::register();
  option('predis', new Predis\Client());

  define('QUEUESET', 'QUEUESET');   // queue index
  define('UUID_SUFFIX', ':UUID');   // queue unique id
  define('QUEUE_SUFFIX', ':queue'); // suffix to identify each queue's LIST
}


dispatch('/', 'hello_world');
  function hello_world()
  {
    return "Hello by restmq-lim server";
  }


dispatch_get('/q', 'restmq_queueset');
  function restmq_queueset()
  {
    $reds = option('predis');
    $members = $reds->smembers(QUEUESET);
    if (empty($members))
    {
      return answer(NOT_FOUND, 'Not found (empty queueset)');
    }
    $queueset = array();
    foreach ($members as $q)
    {
      $queueset[] = '/q/' . $q;
    }
    return answer(HTTP_OK, json_encode($queueset));
  }

// A queue can be accessed as /q/<queuename>
// GET requests will dequeue an object.
dispatch_get(array('/q/*', array("queue")), 'restmq_get');
  function restmq_get()
  {
    $queue = params('queue');
    if (empty($queue))
    {
      return answer(NOT_FOUND, 'Not found');
    }
    $queue = $queue . QUEUE_SUFFIX;
    $reds = option('predis');
    $env = env();
    // soft = true doesnt rpop values
    $soft = isset($env['GET']['value']) ? $env['GET']['value'] : '';
    $b = empty($soft)
      ? $reds->rpop($queue)
      : $reds->lindex($queue, -1);
    if (empty($b))
    {
      return answer(NOT_FOUND, 'Not found (empty queue)');
    }
    $v = $reds->get($b);
    return answer(HTTP_OK,
      empty($v)
        ? 'empty value'
        : json_encode(array('value' => $v, 'key' => $b)));
  }

// POST requests inserts an object in the queue
dispatch_post(array('/q/*', array("queue")), 'restmq_post');
  function restmq_post()
  {
    $queue = params('queue');
    if (empty($queue))
    {
      return answer(NOT_FOUND, 'Not found');
    }
    $env = env();
    $value = isset($env['POST']['value']) ? $env['POST']['value'] : '';
    $q1 = $queue . QUEUE_SUFFIX;
    $reds = option('predis');
    $uuid = $reds->incr($queue . UUID_SUFFIX);
    $reds->sadd(QUEUESET, $q1);
    $lkey = $queue . ':' . $uuid;
    $reds->set($lkey, $value);
    $reds->lpush($q1, $lkey);
    return answer(HTTP_OK, '{ok, ' . $lkey . '}');
  }


run();

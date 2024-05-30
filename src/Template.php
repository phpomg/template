<?php

declare(strict_types=1);

namespace PHPOMG\Template;

use Psr\SimpleCache\CacheInterface;
use SplPriorityQueue;
use Throwable;

class Template
{
    protected $cache = null;

    protected $extends = [];
    protected $finder;

    protected $data = [];
    protected $filename = '';

    public function __construct()
    {
        $this->finder = new SplPriorityQueue;

        if (!in_array('tpls', stream_get_wrappers())) {
            stream_wrapper_register('tpls', Stream::class);
        }

        $this->addFinder(function (string $tpl): ?string {
            if (is_file($tpl)) {
                return file_get_contents($tpl);
            }
            return null;
        }, -100);
    }

    public function setCache(CacheInterface $cache): self
    {
        $this->cache = $cache;
        return $this;
    }

    private function getContent(string $tpl): string
    {
        foreach (clone $this->finder as $finder) {
            $res = $finder($tpl);
            if (!is_null($res)) {
                return $res;
            }
        }
        throw new TplNotFoundException('template [' . $tpl . '] is not found!');
    }

    public function addFinder(callable $callable, $priority = 0): self
    {
        $this->finder->insert($callable, $priority);
        return $this;
    }

    public function extend(string $preg, callable $callback): self
    {
        $this->extends[$preg] = $callback;
        return $this;
    }

    public function assign($name, $value = null): self
    {
        if (is_array($name)) {
            $this->data = array_merge($this->data, $name);
        } else {
            $this->data[$name] = $value;
        }
        return $this;
    }

    public function render(string $tpl, array $data = []): string
    {
        return $this->renderString($this->getContent($tpl), $data, $tpl);
    }

    public function renderString(string $string, array $data = [], string $filename = null): string
    {
        if ($data) {
            $this->assign($data);
        }

        if (is_string($filename)) {
            $cache_key = str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', 'tpl_' . $filename);
        } else {
            $cache_key = 'tpl_' . md5($string);
        }

        if ($this->cache && $this->cache->has($cache_key)) {
            $code = $this->cache->get($cache_key);
        } else {
            $code = $this->parse($string);
            if ($this->cache) {
                $this->cache->set($cache_key, $code);
            }
        }

        $this->filename = 'tpls://' . (is_string($filename) ? $filename : md5($string));
        file_put_contents($this->filename, $code);

        return (function () {
            try {
                ob_start();
                extract($this->data);
                include $this->filename;
                return ob_get_clean();
            } catch (Throwable $th) {
                throw new RenderException($th->getMessage(), $th->getCode(), $th);
            }
        })();
    }

    public function parse(string $string): string
    {
        $literals = [];
        $string = preg_replace_callback('/{literal}([\s\S]*){\/literal}/Ui', function ($matchs) use (&$literals) {
            $key = '#' . md5($matchs[1]) . '#';
            $literals[$key] = $matchs[1];
            return $key;
        }, $string);

        $tags = [
            '/\{(foreach|if|for|switch|while)\s+(.*)\}/Ui' => function ($matchs) {
                return '<?php ' . $matchs[1] . ' (' . $matchs[2] . ') { ?>';
            },
            '/\{function\s+(.*)\}/Ui' => function ($matchs) {
                return '<?php function ' . $matchs[1] . '{ ?>';
            },
            '/\{php\s+(.*)\s*;?\s*\}/Ui' => function ($matchs) {
                return '<?php ' . $matchs[1] . '; ?>';
            },
            '/\{dump\s+(.*)\s*;?\s*\}/Ui' => function ($matchs) {
                return '<pre><?php ob_start();var_dump(' . $matchs[1] . ');echo htmlspecialchars(ob_get_clean()); ?></pre>';
            },
            '/\{print\s+(.*)\s*;?\s*\}/Ui' => function ($matchs) {
                return '<pre><?php echo htmlspecialchars(print_r(' . $matchs[1] . ', true)); ?></pre>';
            },
            '/\{echo\s+(.*)\s*;?\s*\}/Ui' => function ($matchs) {
                return '<?php echo ' . $matchs[1] . '; ?>';
            },
            '/\{case\s+(.*)\}/Ui' => function ($matchs) {
                return '<?php case ' . $matchs[1] . ': ?>';
            },
            '/\{default\s*\}/Ui' => function ($matchs) {
                return '<?php default: ?>';
            },
            '/\{php\}/Ui' => function ($matchs) {
                return '<?php ';
            },
            '/\{\/php\}/Ui' => function ($matchs) {
                return ' ?>';
            },
            '/\{\/(foreach|if|for|function|switch|while)\}/Ui' => function ($matchs) {
                return '<?php } ?>';
            },
            '/\{\/(case|default)\}/Ui' => function ($matchs) {
                return '<?php break; ?>';
            },
            '/\{(elseif)\s+(.*)\}/Ui' => function ($matchs) {
                return '<?php }' . $matchs[1] . '(' . $matchs[2] . '){ ?>';
            },
            '/\{else\/?\}/Ui' => function ($matchs) {
                return '<?php }else{ ?>';
            },
            '/\{include\s*([\w\-_\.,@\/]*)\}/Ui' => function ($matchs) {
                $string = '';
                foreach (explode(',', $matchs[1]) as $tpl) {
                    $string .= $this->getContent($tpl);
                }
                return $this->parse($string);
            },
            '/\{(\$[^{}\'"]*)((\.[^{}\'"]+)+)\}/Ui' => function ($matchs) {
                return '<?php echo htmlspecialchars(' . $matchs[1] . substr(str_replace('.', '\'][\'', $matchs[2]), 2) . '\']' . '); ?>';
            },
            '/\{(\$[^{}]*)\}/Ui' => function ($matchs) {
                return '<?php echo htmlspecialchars(' . $matchs[1] . '); ?>';
            },
            '/\{:([^{}]*)\s*;?\s*\}/Ui' => function ($matchs) {
                return '<?php echo htmlspecialchars(' . $matchs[1] . '); ?>';
            },
        ];
        $tags = array_merge($tags, $this->extends);
        foreach ($tags as $preg => $callback) {
            $string = preg_replace_callback($preg, $callback, $string);
        }
        return str_replace(
            array_keys($literals),
            array_values($literals),
            $string
        );
    }
}

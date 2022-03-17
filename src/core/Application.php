<?php

namespace Luxulsolutions\Dixel\core;

use PHPHtmlParser\Dom;
use splitbrain\phpcli\Exception;
use splitbrain\phpcli\Options;
use Unirest\Request;

class Application extends \splitbrain\phpcli\CLI
{

    public const DEFAULT_MARGIN = 40;

    public $baseurl = 'https://dixel-opt.ru';
    protected $sessionid = null;
    public $margin = null;
    public $top = null;
    public $fixed = null;
    public $log = null;

    /**
     * @inheritDoc
     */
    protected function setup(Options $options)
    {
        $options->setHelp('Правильная выгрузка YML с dixel-opt.ru');
        $options->registerOption('sessionid', 'ID сессии из cookies', 's', 'sessionid');
        $options->registerOption('margin', 'Маржа в %', 'm', 'margin');
        $options->registerOption('top', 'Потолок маржи в рублях', 't', 'top');
        $options->registerOption('fixed', 'Фиксированная маржа в рублях при достижении потолка', 'f', 'fixed');
        $options->registerOption('log', 'Вывод журнала вычислений', 'l');
    }

    /**
     * @inheritDoc
     */
    protected function main(Options $options)
    {
        if ($options->getOpt('log')) $this->log = true;
        $this->sessionid = $options->getOpt('sessionid', null);
        $this->margin = $options->getOpt('margin', null);
        $this->top = $options->getOpt('top', null);
        $this->fixed = $options->getOpt('fixed', null);
        if (empty($this->sessionid)) $this->error('Не установлен ID сессии из cookies');
        if (empty($this->margin)) {
            $this->alert('Не установлена маржа, используем по умолчанию ' . self::DEFAULT_MARGIN . '%');
            $this->margin = self::DEFAULT_MARGIN;
        }
        if (empty($this->top)) $this->alert('Не установлен потолок маржи в рублях - если ты лютый барыга, то всё норм');
        if ($this->top && empty($this->fixed)) {
            $this->alert('Не установлена фиксированная маржа в рублях - использую потолок');
            $this->fixed = $this->top;
        }
        Request::verifyPeer(false);
        Request::cookie('sessionid=' . $this->sessionid);
        $this->go();
    }

    /**
     * @param $page
     * @param string $method
     * @param array $headers
     * @param array $body
     * @return false|\Unirest\Response
     */
    public function request($page, $method = 'get', $headers = [], $body = [])
    {
        $result = false;
        if ($method === 'get') {
            $result = Request::get("{$this->baseurl}/{$page}", $headers, $body);
        } elseif ($method === 'post') {
            $result = Request::post("{$this->baseurl}/{$page}", $headers, $body);
        }
        return $result;
    }

    public function go(){

        foreach ($this->lists() as $name=>$url){
            $this->info("Обработка списка {$name}");
            $products = [];
            foreach ($this->list($url) as $product){
                $productParsed = $this->product($product);
                $products[$productParsed['code']] = $productParsed;
            }
            $this->info("Обработка YML {$name}");
            $yml = new \SimpleXMLElement(file_get_contents($this->baseurl.$url.'/yml/'));
            foreach($yml->shop->offers->offer as $offer){
                /**
                 * @var \SimpleXMLElement $offer
                 */
                $this->info("Ищем {$offer->vendorCode}");
                if(isset($products["{$offer->vendorCode}"]) && $product = $products["{$offer->vendorCode}"]){
                    $offer['id'] = $product['id'];
                    $offer->price = $product['price'];
                    $offer->addChild('vendor_price', $product['vendor_price']);
                    $offer->addChild('barcode', $product['barcode']);
                    unset($offer->outlets);
                    unset($offer->url);
                    unset($offer->currencyId);
                } else {
                    $this->info("Удаление из YML {$offer->vendorCode}");
                    unset($offer);
                }
                echo "#";
            }
            echo "#\n";
            if(!file_exists(__DIR__."/../../runtime/results")){
                mkdir(__DIR__."/../../runtime/results", 0777, true);
            }
            $this->info("Сохраняем YML ".__DIR__."/../../runtime/results/{$name}.yml");
            $yml->asXML(__DIR__."/../../runtime/results/{$name}.yml");
        }


    }

    public function lists()
    {
        $response = $this->request('productlists');
        $dom = (new Dom())->loadStr($response->body);
        $lists = [];
        foreach ($dom->find('#sidebar > li > a') as $item) {
            /**
             * @var Dom\Node\AbstractNode $item
             */
            $this->info("Обнаружен список выгрузки {$item->find('span')[0]->text} с {$item->find('span')[1]->text} товаров");
            if ((int)$item->find('span')[1]->text > 0)
                $lists[$item->find('span')[0]->text] = $item->getAttribute('href');
        }
        return $lists;
    }

    public function list($url, $next = '', $stored = []){
        $response = $this->request($url.$next);
        $dom = (new Dom())->loadStr($response->body);
        $products = $stored;
        foreach ($dom->find('tbody > .product') as $product){
            $purl = $product->find('td')[1]->find('a')->getAttribute('href');
            $name = html_entity_decode($product->find('td')[1]->find('a')->text);
            $this->info("Обнаружен продукт {$name}");
            $products[] = $purl;
        }
        $pagerCount = $dom->find('.pager > li')->count();
        if($pagerCount > 0){
            $nextPage = ($dom->find('.pager > li')[$pagerCount-1])
                ->find('a')
                ->getAttribute('href');
            if($nextPage!='#'){
                $products = $this->list($url, $nextPage, $products);
            }
        }

        return $products;
    }

    public function product($url){
        $response = $this->request($url);
        $dom = (new Dom())->loadStr($response->body);
        $id = trim($dom->find('#product-id')[0]->getAttribute('value'));
        $code = $dom->find('#wrap > div.container.js-main-container > div > div > div.col-md-8 > div > div > div:nth-child(2) > div.col-md-5 > p:nth-child(4) > small')[0];
        if($code) $code = trim($code->text);
        $barcode = $dom->find('#wrap > div.container.js-main-container > div > div > div.col-md-8 > div > div > div:nth-child(2) > div.col-md-7 > small > p')[0];
        if($barcode) $barcode = $barcode->text;
        $vendorPrice = preg_replace(["/\s/i", "/&#?[a-z0-9]{2,8};/i", "/,/i"],["","","."],$dom->find('#product_price')[0]->text);
        $margin = ($vendorPrice / 100) * $this->margin;
        if($this->top && $margin > $this->top){
            if($this->fixed) $margin = $this->fixed;
            else $margin = $this->top;
        }
        $price = $margin + $vendorPrice;
        $this->info("Рассчитан продукт {$code}");
        return [
            'id'=>$id,
            'code'=>$code,
            'barcode'=>$barcode,
            'vendor_price'=>$vendorPrice,
            'price'=>$price
        ];
    }

    public function catalog()
    {
        $response = $this->request('catalog');
        $this->log('info', 'Ответ: ' . print_r($response->raw_body, true));
    }
}
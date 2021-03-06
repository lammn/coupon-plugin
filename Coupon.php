<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace Plugin\Coupon;

use Eccube\Event\RenderEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form as Error;
use Eccube\Service\ShoppingService;

class Coupon
{

    private $app;

    /**
     * @var string 非会員用セッションキー
     */
    private $sessionKey = 'eccube.front.shopping.nonmember';

    /**
     * コンストラクタ.
     * @param unknown $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    // =========================================================
    // shopping - renderのEvent
    // =========================================================
    /**
     * [shopping/index]表示の時のEvent Fock.
     * クーポン関連項目を追加する
     *
     * @param FilterResponseEvent $event
     */
    public function onRenderShoppingBefore(FilterResponseEvent $event)
    {
        $app = &$this->app;
        $request = $event->getRequest();
        $response = $event->getResponse();

        // Formにクーポン関連情報を追加する
        // クーポン関連項目を追加する
        $response->setContent($this->getHtml($request, $response));
        $event->setResponse($response);
    }

    /**
     * [shopping/complete]表示の時のEvent Fock.
     * クーポン関連項目を追加する
     *
     * @param FilterResponseEvent $event
     */
    public function onRenderShoppingConfirmBefore(FilterResponseEvent $event)
    {
        $app = &$this->app;
        $request = $event->getRequest();
        $response = $event->getResponse();

        // Formにクーポン関連情報を追加する
        // クーポン関連項目を追加する
        $response->setContent($this->getHtml($request, $response));
        $event->setResponse($response);
    }

    /**
     * [order/{id}/edit]表示の時のEvent Fock.
     * クーポン関連項目を追加する
     *
     * @param FilterResponseEvent $event
     */
    public function onRenderAdminOrderEditAfter(FilterResponseEvent $event)
    {
        $app = &$this->app;

        $request = $event->getRequest();
        $response = $event->getResponse();

        // 受注IDを取得する
        $orderId = $request->get('id');
        if(is_null($orderId)) {
            return;
        }

        // クーポン受注情報を取得する
        $repCouponOrder = $this->app['eccube.plugin.coupon.repository.coupon_order'];

        // クーポン受注情報を取得する
        $CouponOrder = $repCouponOrder->findUseCouponByOrderId($orderId);
        if(is_null($CouponOrder)) {
            return;
        }

        // クーポン受注情報からクーポン情報を取得する
        $repCoupon = $this->app['eccube.plugin.coupon.repository.coupon'];
        $Coupon = $repCoupon->find($CouponOrder->getCouponId());
        if(is_null($Coupon)) {
            return;
        }

        // 編集画面にクーポン表示を追加する
        $this->getHtmlOrderEdit($request, $response, $event, $Coupon);
    }

    /**
     * 受注情報編集画面にクーポン情報を追加する
     * @param unknown $request
     * @param unknown $response
     * @param unknown $event
     * @param unknown $Coupon
     */
    private function getHtmlOrderEdit($request, $response, $event, $Coupon) {
        $source = $event->getResponse()->getContent();
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $source);
        $dom->encoding = "UTF-8";

        /** @var DOMNodeList */
        $Elements = $dom->getElementsByTagName("*");
        $parentNode = null;
        $operationNode = null;
        for ($i = 0; $i < $Elements->length; $i++) {
            if (@$Elements->item($i)->attributes->getNamedItem('class')->nodeValue == "col-md-9") {
                // 親ノードを保持する
                $parentNode = $Elements->item($i);
             } else if (@$Elements->item($i)->attributes->getNamedItem('class')->nodeValue == "row hidden-xs hidden-sm") {
                // 操作部ノードを保持する
                 $operationNode =  $Elements->item($i);
             }
        }

        // 親ノード、操作部（登録ボタン、戻るリンク）ノードが取得できた場合のみクーポン情報を表示する
        if (!is_null($parentNode) && !is_null($operationNode)) {

            // 追加するクーポン情報のHTMLを取得する.
            $insert = $this->app['twig']->render(
                'Coupon/View/admin/order_edit_coupon.twig',
                array('form' => $Coupon)
                );

            $template = $dom->createDocumentFragment();
            $template->appendXML($insert);


            // ChildNodeの途中には追加ができないため、一旦操作部を削除する
            // その後、クーポン情報、操作部の順にappendする

            // 操作部のノードを削除
            $parentNode->removeChild($operationNode);

            // クーポン情報のノードを追加
            $parentNode->appendChild($template);

            // 操作部のノードを削除
            $parentNode->appendChild($operationNode);

            $event->getResponse()->setContent($dom->saveHTML());
        }

    }


    /**
     * 注文クーポン情報に受注日付を登録する.
     *
     */
    public function onControllerShoppingConfirmBefore() {
        $cartService = $this->app['eccube.service.cart'];

        $preOrderId = $cartService->getPreOrderId();
        if(is_null($preOrderId)) {
            return;
        }

        $repository = $this->app['eccube.plugin.coupon.repository.coupon_order'];

        // クーポン受注情報を取得する
        $CouponOrder = $repository->findOneBy(array(
            'pre_order_id' => $preOrderId
        ));

        if(is_null($CouponOrder)) {
            return;
        }
        // 更新対象データ

        $CouponOrder->setOrderDate(new \DateTime());
        $CouponOrder->setUpdateDate(new \DateTime());

        $repository->save($CouponOrder);
    }

    // =========================================================
    // クラス内メソッド
    // =========================================================
    /**
     * ご注文内容のご確認画面のHTMLを取得し、関連項目を書き込む
     * お支払方法の下に下記の項目を追加する.(id=confirm_main )
     * ・クーポンコード入力
     * ・クーポンコード反映
     * 送料のの下に下記の項目を追加する.(class=total_box total_amountの上)
     * ・値引き表示
     *
     * @param unknown $request
     * @param unknown $response
     * @return mixed
     */
    private function getHtml($request, $response) {

        // HTMLを取得し、DOM化
        $crawler = new Crawler($response->getContent());
        $error = false;
	    $html = '';
	    foreach ($crawler as $domElement) {
	        $domElement->ownerDocument->formatOutput = true;
	        $html .= $domElement->ownerDocument->saveHTML();
	    }
	    $html = html_entity_decode($html, ENT_NOQUOTES, 'UTF-8');

        // Formの値を取得する
        $form = $this->getShoppingForm();
        $data = $form->getData();

        // 受注データを取得
        $Order = $this->getOrder();
        if(is_null($Order)) {
            return $html;
        }
        // サービスの取得
        $service = $this->app['eccube.plugin.coupon.service.coupon'];

        // カートの中に有効な商品がある調べる
        // 商品がない場合はクーポンコード項目を表示しない
        if(!$service->isOrderInActiveCoupon($Order)) {
            return $html;
        }

        // ----------------------------------
        // クーポンコード入力項目追加
        // ----------------------------------
        // クーポンコードが存在しない場合、エラーを設定する
        // クーポンコードを取得する
        $formCouponCd = $this->getShoppingForm(false);
        $couponCd = $this->getCouponCd($data, $Order->getPreOrderId());
        $Coupon = null;
        $discount = 0;
        $nonMemberEmail = '';
        if (!$this->app->isGranted('ROLE_USER')){
            $nonMemberEmail = $this->app['eccube.service.shopping']->getNonMember($this->sessionKey)->getEmail();
        }
        if(!is_null($couponCd) && strlen($couponCd) > 0) {
            $Coupon = $this->getCouponByCouponCd($couponCd);
            $discount = $service->recalcOrder($Order, $Coupon);
            $existCoupon = $service->existsCouponProduct($Coupon, $Order);
            $couponUsedOrNot = $this->checkCouponUsedOrNot($couponCd, $nonMemberEmail);
            $checkCouponUseTime = $this->checkCouponUseTime($couponCd);
            if(!$existCoupon) {
                $formCouponCd->get("coupon_cd")->addError(new Error\FormError('front.plugin.coupon.shopping.notexists'));
                $error = true;
            }
            if (!$couponUsedOrNot && $existCoupon) {
                $formCouponCd->get("coupon_cd")->addError(new Error\FormError('front.plugin.coupon.shopping.sameuser'));
                $error = true;
            }
            if (!$checkCouponUseTime && $existCoupon) {
                $formCouponCd->get("coupon_cd")->addError(new Error\FormError('front.plugin.coupon.shopping.couponusetime'));
                $error = true;
            }
            if ($Order->getTotal() <= $discount && $existCoupon) {
                $formCouponCd->get("coupon_cd")->addError(new Error\FormError('front.plugin.coupon.shopping.minus'));
                $error = true;
            }
        }
        $formCouponCd->get("coupon_cd")->setData($couponCd);

        try {
            // ※idなりclassなりがきちんとつかないとDOMをいじるのは難しい

            $parts = $this->app->renderView('Coupon/View/coupon_shopping_item.twig', array('form' => $formCouponCd->createView()));

            // このタグを前後に分割し、間に項目を入れ込む
            $form  = $crawler->filter('#confirm_main')->last()->html();
            $pos = strrpos($form, '<h2 class="heading02">');
            if ($pos !== false) {
                $beforeForm = substr($form, 0, $pos);
                $afterForm = substr($form, $pos);
                $newForm = $beforeForm . $parts . $afterForm;
                $html = str_replace($form, $newForm , $html);
            }

            // ----------------------------------
            // 値引き項目追加 / 合計金額上書き
            // ----------------------------------
            if(!$error && $Coupon != null){
                $total = $Order->getTotal() - $discount;
                $Order->setDiscount($discount);
                $Order->setTotal($total);
                $Order->setPaymentTotal($total);
                // クーポン受注情報を保存する
                $service->saveCouponOrder($Order, $Coupon, $couponCd, $nonMemberEmail);
                // 合計、値引きを再計算し、dtb_orderデータに登録する
                $this->app['orm.em']->persist($Order);
                $this->app['orm.em']->flush($Order);

                // 値引き額をマイナス表示にする
                $Order->setDiscount($Order->getDiscount() * -1);
            }

            // このタグを前後に分割し、間に項目を入れ込む
            // 元の合計金額は書き込み済みのため再度書き込みを行う
            $parts = $this->app->renderView('Coupon/View/discount_shopping_item.twig',
                        array('Order' => $Order,
                        'form' => $formCouponCd->createView()
                        ));
            $form  = $crawler->filter('#confirm_side .total_box')->last()->html();

            $pos = strrpos($form, '</dl>');
            if ($pos !== false) {
                $beforeForm = substr($form, 0, $pos);
                $newForm = $beforeForm . $parts;
                $html = str_replace($form, $newForm , $html);
            }

         } catch (\InvalidArgumentException $e) {
            // no-op
        }
        return $html;

    }

    /**
     * クーポン情報を取得する
     * @param unknown $couponCd
     */
    private function getCouponByCouponCd($couponCd) {
        return $this->app['eccube.plugin.coupon.repository.coupon']->findActiveCoupon($couponCd, new \DateTime());
    }

    /**
     * 受注データを取得
     */
    private function getOrder() {
        // 受注データを取得
        $orderService = $this->app['eccube.service.order'];
        $cartService = $this->app['eccube.service.cart'];

        $preOrderId = $cartService->getPreOrderId();
        $Order = $this->app['eccube.repository.order']->findOneBy(array(
            'pre_order_id' => $preOrderId,
            'OrderStatus' => $this->app['config']['order_processing']
        ));
        return $Order;
    }

    /**
     * Formを取得する
     * @param boolean $handleRequest
     */
    private function getShoppingForm($handleRequest = true) {
        $form = $this->app['form.factory']->createBuilder()
        ->add('coupon_cd', 'text', array(
            'label' => 'クーポンコード',
            'required' => false,
            'trim' => true,
        ))->getForm();

        if($handleRequest) {
            $form->handleRequest($this->app['request']);
        }
        return $form;

    }

    /**
     * クーポンコードを取得する.
     * Formにない場合はDBから取得する
     * @param unknown $formData
     * @param unknown $preOrderId
     */
    private function getCouponCd($formData, $preOrderId) {
        $couponCd = $formData['coupon_cd'];
        if(!is_null($couponCd) && strlen($couponCd) > 0) {
            return $couponCd;
        }

        $CouponOrder = $this->app['eccube.plugin.coupon.repository.coupon_order']
            ->findOneBy(array(
                'pre_order_id' => $preOrderId
            ));

        if(!is_null($CouponOrder)) {
            return $CouponOrder->getCouponCd();
        }
        return null;
    }

    /**
     *  ユーザはクーポン１回のみ利用できる
     * @param unknown $couponCd
     *  @param unknown $email
     */
    private function checkCouponUsedOrNot($couponCd, $email) {
        if ($this->app->isGranted('ROLE_USER')) {
            $repository = $this->app['eccube.plugin.coupon.repository.coupon_order'];
            $userId = $this->app->user()->getId();
            $result = $repository->findUseCouponMember($couponCd, $userId);
            if($result != null)
                return false;
        }else{
            $repository = $this->app['eccube.plugin.coupon.repository.coupon_order'];
            $result = $repository->findUseCouponNonMember($couponCd, $email);
            if($result != null)
                return false;
        }
        return true;
    }

    /**
     *  クーポンの利用回数のチェック
     * @param unknown $couponCd
     */
    private function checkCouponUseTime($couponCd) {
        $couponOrderRepository = $this->app['eccube.plugin.coupon.repository.coupon_order'];
        $couponRepository = $this->app['eccube.plugin.coupon.repository.coupon'];
        $Coupon = $couponRepository->findOneBy(array('coupon_cd' => $couponCd ));
        if($Coupon != null){
            $count = $couponOrderRepository->countCouponByCd($couponCd);
            if($Coupon->getCouponUseTime() <= $count['1'])
                return false;
        }
        return true;
    }
    
}

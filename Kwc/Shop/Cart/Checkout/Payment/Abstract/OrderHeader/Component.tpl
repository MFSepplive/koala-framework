<div class="<?=$this->rootElementClass?>">
    <div class="receiver">
        <p>
            <strong><?=$this->order->title?> <?=$this->order->firstname?> <?=$this->order->lastname?></strong><br />
            <?=$this->order->street?><br />
            <?=$this->order->country?> - <?=$this->order->zip?> <?=$this->order->city?>
        </p>
    </div>
    <div class="receiverInfo">
        <p>
            <?=$this->order->email?><br />
            <?=$this->order->phone?>
        </p>
    </div>
    <div class="receiverComment">
        <p>
            <?php if ($this->order->comment) { ?>
                <strong><?=$this->data->trlKwf('Your Comment')?></strong><br />
                <?=$this->order->comment?>
            <?php } ?>
        </p>
    </div>
    <?php if ($this->paymentTypeText) { ?>
    <div class="orderInfo">
        <p>
            <?=$this->data->trlKwf('You pay by')?> <strong><?=$this->paymentTypeText?></strong>.
        </p>
    </div>
    <?php } ?>
</div>

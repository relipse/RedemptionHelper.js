RedemptionHelper.js
===================

This is a javascript helper for the popular Christian card game Redemption made by Cactus Game Design.
Read below for more info:

```
  RedemptionHelper by Jim Kinsman (github.com/relipse)
  Thanks for original database from http://covenantgames.com/RedList.zip
 
  Usage: (need jquery for these examples)
    $(function(){
       RedemptionHelper.cards.jQboldCards();   //find all redemption cards in body and surround with <b class=".redempcard"> tag
       RedemptionHelper.cards.jQinsertCardInfo();   //inject <span class=".redempcardinfo">....</span> after the tag above
    });
  And set your css styles accordingly to make it look nice: 
    .redempcard and .redempcardinfo
```

If you are reading a website with a bunch of Redemption cards, you can actually inject this script using the Chrome console like so:

```
loadJs = function(s) {

    var script = document.createElement("script");

    // This script has a callback function that will run when the script has
    // finished loading.
    script.src = s;
    script.type = "text/javascript";
    document.getElementsByTagName("head")[0].appendChild(script);
}
loadJs('//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js');
loadJs('http://raw.github.com/relipse/RedemptionHelper.js/master/RedemptionHelper.js'); 
//wait a few seconds for it to load
 RedemptionHelper.cards.jQboldCards('#msg_514142');
 RedemptionHelper.cards.jQinsertCardInfo();
$('.redempcardinfo').css('font-size','9px').css('color','red');
```

and remember to change #msg_514142 into whatever jquery key you can think of (even 'body' works -- but can be hackish)

<!doctype html>
<html>
<head>
<meta charset="utf-8"> 
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
<!-- boring method -- <script src="RedemptionHelper.js"></script> -->
<script>
 $(function(){

  $.getScript( "RedemptionHelper.js", function( data, textStatus, jqxhr ) {
     console.log( data ); // Data returned
     console.log( textStatus ); // Success
     console.log( jqxhr.status ); // 200
     console.log( "Load was performed." );
     
     $('#btnUseHelper1').click(function(){
          RedemptionHelper.cards.jQboldCards();
          RedemptionHelper.cards.jQinsertCardInfo(); 
          alert('Good! First you ran jQboldCards() which found and isolated all the Redemption Cards, '+
               'then we injected the descriptions next to each card using jQinsertCardInfo(). '+
               'Next time, don\'t run jQboldCards() on the whole body. (try jQboldCards("p"))');          
       });
     
     
   });
       
});
</script>
<style>
.redempcard {
   font-family: Tahoma;
}

.redempcardinfo {
   font-size: 70%;
}
</style>
</head>
<body>
<div>
<button id="btnUseHelper1">Use Redemption Helper 1</button>
</div>
blah blah lbha Blast of Fire fajsldfkjalsdfj alskdfj Bound Burst of Light

Demonic Deception blah blah lbha Blast of Fire fajsldfkjalsdfj alskdfj Bound Burst of Light

Demonic Deception blah blah lbha Blast of Fire fajsldfkjalsdfj alskdfj Bound Burst of Light

Demonic Deception blah blah lbha Blast of Fire fajsldfkjalsdfj alskdfj Bound Burst of Light

Demonic Deception blah blah lbhaBlast of Fire fajsldfkjalsdfj alskdfjBound Burst of Light

Demonic Deception 
<p>af;lskjdf;lkajsdf; lkasdfblah blah lbha Blast of Fire fajsldfkjalsdfj alskdfj Bound Burst of Light

Demonic Deception </p>

<p>as;ldjf;sadl</p>
</body>
</html>
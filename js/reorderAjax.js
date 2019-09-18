jQuery(document).ready(function() {
	initSelectCategory();
	
	
	initMainForm();
});



/**
  * Inițializează forma principală:
  * - face ca lista de articole să fie sortabilă
  * - Când faceți clic pe unul dintre butoanele radio, înregistrați preferința relevantă *
*/
function initMainForm(){
	
	jQuery("#sortable-list").sortable(
		{
			 update: function( event, ui ) {
				
				jQuery('#spinnerAjaxUserOrdering').show();
				
				data = {
					'action'					: 'user_ordering',
					'order'						: jQuery(this).sortable('toArray').toString(),
					'category'					: jQuery(this).attr("rel"),
					'deefuseNounceUserOrdering'	: deefusereorder_vars.deefuseNounceUserOrdering
				}
				jQuery.post(ajaxurl, data, function (response){
					//alert(response);
					jQuery('#spinnerAjaxUserOrdering').hide();
				});
			 }
		}
	);
	
	jQuery("#form_result input.option_order").change(function (){
		jQuery('#spinnerAjaxRadio').show();
		
		if(jQuery("#form_result input.option_order:checked").val() ==  "true" && jQuery("#sortable-list li").length >=2){
			jQuery('#spinnerAjaxUserOrdering').show();
			
			data = {
				'action'					: 'user_ordering',
				'order'						: jQuery("#sortable-list").sortable('toArray').toString(),
				'category'					: jQuery("#sortable-list").attr("rel"),
				'deefuseNounceUserOrdering'	: deefusereorder_vars.deefuseNounceUserOrdering
			}
			jQuery.post(ajaxurl, data, function (response){
				//alert(response);
				jQuery('#spinnerAjaxUserOrdering').hide();
			});
			
		}
		
		
		jQuery("#form_result input.option_order").attr('disabled', 'disabled');
		
		data = {
			'action'				: 'cat_ordered_changed',
			'current_cat'			: jQuery("#termIDCat").val(),
			'valueForManualOrder'	: jQuery("#form_result input.option_order:checked").val(),
			'deefuseNounceOrder'	: deefusereorder_vars.deefuseNounceCatReOrder
		}
		
		jQuery.post(ajaxurl, data, function (response){
			jQuery('#debug').html(response);
			jQuery('#spinnerAjaxRadio').hide();
			jQuery("#form_result input.option_order").attr('disabled', false);
		});
		
		return false;
	})
}

/**
  * Inițializează comportamentul JavaScript atunci când alegi categoria (prima formă)
  * La schimbare, stocăm cârligul taxonomiei respective într-un câmp ascuns
  * și facem formularul
*/
function initSelectCategory(){
	jQuery("#selectCatToRetrieve").change(
		function(event){
			var taxonomy = jQuery("#selectCatToRetrieve option:selected").parent().attr("id");
			jQuery("#taxonomyHiddenField").val(taxonomy);
			
			jQuery("form#chooseTaxomieForm").submit();
		}
	);
}
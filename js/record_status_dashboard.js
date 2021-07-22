CrossProjectPipingModule = {};

CrossProjectPipingModule.addButtonAfterJqueryLoaded = function() {
	if (typeof($) != 'undefined') {
		// wait 
		$(function() {
			$("form#dashboard-config + div").append("<button id='pipe_all_data' class='btn btn-xs btn-rcpurple fs13'>Pipe All Data</button>");
			
			$("body").on("click", "button#pipe_all_data", function(event) {
				console.log('click event from pipe_all_data:', event);
			});
		});
	} else {
		setTimeout(CrossProjectPipingModule.addButtonAfterJqueryLoaded, 100);
	}
}

CrossProjectPipingModule.addButtonAfterJqueryLoaded();
CrossProjectPipingModule = {};

CrossProjectPipingModule.ajax_endpoint = "AJAX_ENDPOINT";

CrossProjectPipingModule.ajax_complete = function(data, status, xhr) {
	console.log("ajax completed", {data: data, status: status, xhr: xhr});
	$(".cpp_pipe_all_loader").css('display', 'none');
	$("button#pipe_all_data").attr('disabled', false);
	
	if (status == 'success' && data.responseJSON && data.responseJSON['success'] == true) {
		// window.location.reload();
	} else {
		if (data.responseJSON && data.responseJSON['error']) {
			alert(data.responseJSON['error']);
		} else {
			alert("The Cross Project Piping module failed to get a response for your action. Please contact a REDCap administrator or the author of this module.");
		}
	}
}

CrossProjectPipingModule.addButtonAfterJqueryLoaded = function() {
	if (typeof($) != 'undefined') {
		// wait 
		$(function() {
			$("form#dashboard-config + div").append("<button id='pipe_all_data' class='btn btn-xs btn-rcpurple fs13'><div class='cpp_pipe_all_loader'></div>Pipe All Data</button>");
			
			$("body").on("click", "button#pipe_all_data", function(event) {
				// show spinning loader icon and disabled pipe button
				$(".cpp_pipe_all_loader").css('display', 'inline-block');
				$("button#pipe_all_data").attr('disabled', true);
				
				// send ajax request to pipe_all_data_ajax endpoint
				$.get({
					url: CrossProjectPipingModule.ajax_endpoint,
					complete: CrossProjectPipingModule.ajax_complete,
				});
			});
		});
	} else {
		setTimeout(CrossProjectPipingModule.addButtonAfterJqueryLoaded, 100);
	}
}

CrossProjectPipingModule.addButtonAfterJqueryLoaded();
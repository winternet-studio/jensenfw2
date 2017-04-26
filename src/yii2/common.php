<?php
namespace winternet\jensenfw2\yii2;

class common {
	public static function process_ajax_submit($options = []) {
		/*
		DESCRIPTION:
		- generate Javascript code for handling response of an Ajax request that produces standard result/output JSON object with 'status', 'result_msg', and 'err_msg' keys in an array
		INPUT:
		- $options : associative array with any of these keys:
			- 'form' : ActiveForm object
			- 'on_error' : name of callback function when submission caused some errors
			- 'on_success' : name of callback function when submission succeeded
			- 'on_complete' : name of callback function that will always be called
		OUTPUT:
		- Javascript expression
		*/
		$js = "function(rsp) {";
		if ($options['form']) {
			// Apply the server-side generated errors to the form fields
			$js .= "var form = $(_clickedButton).parents('form');
var errorCount = 0;
if (typeof rsp.err_msg_ext != 'undefined') {
	for (var x in rsp.err_msg_ext) {if (rsp.err_msg_ext.hasOwnProperty(x)){errorCount++;}}
	form.yiiActiveForm('updateMessages', rsp.err_msg_ext);
}";  // NOTE: errorCount MUST be determined before form.yiiActiveForm() because it modifies rsp.err_msg_ext!
		} else {
			$js .= "var form, errorCount;";
			$js .= "if (rsp.err_msg) errorCount = rsp.err_msg.length;";
		}

		if ($options['on_error'] || $options['on_success']) {
			$js .= "if (errorCount > 0) {". ($options['on_error'] ? $options['on_error'] .'({form:form, rsp:rsp, errorCount:errorCount});' : '') ."} else {". ($options['on_success'] ? $options['on_success'] .'({form:form, rsp:rsp, errorCount:errorCount});' : '') ."}";
		}
		if ($options['on_complete']) {
			$js .= $options['on_complete'] .'({form:form, rsp:rsp, errorCount:errorCount});';
		}
		$js .= "}";
		return new \yii\web\JsExpression($js);
	}

	public static function process_ajax_submit_error($options = []) {
		/*
		DESCRIPTION:
		- generate Javascript code for handling a failed Ajax request with a JSON response, eg. a 500 Internal Server Error
		INPUT:
		- $options : associative array with any of these keys:
			- none yet!
		OUTPUT:
		- Javascript expression
		*/
		$js = "function(xhr, textStatus, errorThrown) {";
		$js .= "var \$bg = \$('<div/>').addClass('jfw-yii2-ajax-error-bg').css({position: 'fixed', top: '0px', left: '0px', width: '100%', backgroundColor: '#595959'}).height(\$(window).height());";
		$js .= "var \$modal = \$('<div/>').addClass('msg').css({position: 'fixed', top: '100px', left: '50%', transform: 'translateX(-50%)', width: '70%', marginLeft: 'auto', marginRight: 'auto', backgroundColor: '#EEEEEE', padding: '30px', boxShadow: '0px 0px 28px 5px #232323'});";
		$js .= "\$modal.html('<h3>'+ errorThrown +'</h3>'+ xhr.responseJSON.message +'<div><button class=\"btn btn-primary\" onclick=\"\$(this).parent().parent().parent().remove();\">OK</button></div>');";
		$js .= "\$bg.append(\$modal);";
		$js .= "\$('body').append(\$bg);";
		$js .= "}";
		return new \yii\web\JsExpression($js);
	}

	public static function add_result_errors($result, &$model, $options = []) {
		/*
		DESCRIPTION:
		- add errors from a Yii model to a standard result array
		- the new key 'err_msg_ext' MUST then be used for processing it (because 'err_msg' might not contain all error messages)
		INPUT:
		- $result : empty variable (null, false, whatever) or an associative array in this format: ['status' => 'ok|error', 'result_msg' => [], 'err_msg' => []]
		- $model : a Yii model
		- $options : associative array with any of these keys: 
			- 'add_existing' : add the existing 'err_msg' array entries to 'err_msg_ext'
		OUTPUT:
		- associative array in the format of $result but with the new key 'err_msg_ext'
		*/
		if (!is_array($result)) {
			$result = [
				'status' => 'ok',
				'result_msg' => [],
				'err_msg' => [],
			];
		}

		$model_errors = $model->getErrors();

		foreach ($model_errors as $attr => $errors) {
			// Generate the form field ID so Yii ActiveForm client-side can apply the error message
			if (!$model_name) {
				$model_name = $model::className();
				$model_name = mb_strtolower(substr($model_name, strrpos($model_name, '\\')+1));
			}

			$result['err_msg_ext'][$model_name .'-'. $attr] = $errors;
		}


		if ($options['add_existing']) {
			if (!empty($result['err_msg'])) {
				$result['err_msg_ext']['_global'] = $result['err_msg'];
			}
		}

		// Ensure correct status
		if (!empty($result['err_msg']) || !empty($result['err_msg_ext'])) {
			$result['status'] = 'error';
		}

		return $result;
	}
}
